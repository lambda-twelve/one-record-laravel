<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;

/**
 * The SDK enqueues; the host delivers. Rows live in the same database as the
 * objects they announce, so a notification exists exactly when the change it
 * reports was committed. Everything beyond enqueue() is the host side of the
 * contract: finding due rows, leasing one to a worker, recording the outcome.
 * Retry state lives here rather than in the queue so a lost job can never
 * lose a notification. Delivery is at least once: a lease that expires while
 * a worker is still delivering lets another worker deliver again, and the
 * recipient deduplicates on the notification id (the Idempotency-Key).
 */
final class DatabaseNotificationOutbox implements NotificationOutbox
{
    /**
     * @param ?Closure(int): void $onEnqueued called with the new row id once the enqueuing transaction has committed (at once outside one), e.g. to queue a delivery job
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
        private readonly ?Closure $onEnqueued = null,
    ) {}

    public function enqueue(OutboundNotification $notification): void
    {
        $id = $this->db->table($this->tables->outbox())->insertGetId([
            'notification_id' => $notification->id,
            'recipient_hash' => IriHash::of($notification->recipient),
            'recipient' => $notification->recipient->value,
            'endpoint' => $notification->suggestedEndpoint(),
            'event_type' => $notification->notification->eventType->name,
            'logistics_object' => $notification->notification->logisticsObject?->value,
            'triggered_by' => $notification->notification->triggeredBy?->value,
            'document' => Json::encode($notification->notification->toJsonLd(), false),
            'created_at' => Timestamps::toDb($notification->createdAt),
            'attempts' => 0,
            'next_attempt_at' => Timestamps::toDb($notification->createdAt),
        ]);
        if ($this->onEnqueued !== null) {
            $onEnqueued = $this->onEnqueued;
            $notify = static function () use ($onEnqueued, $id): void {
                $onEnqueued($id);
            };
            // Only once the row is committed: a job that ran before would find no row, and a rolled-back
            // enqueue must leave no job behind. Outside a transaction the connection runs it at once.
            $this->db instanceof Connection ? $this->db->afterCommit($notify) : $notify();
        }
    }

    public function find(int $id): ?PendingNotification
    {
        $row = Row::first($this->db->table($this->tables->outbox())->where('id', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Ids of undelivered rows whose next attempt is due, oldest first.
     *
     * @return list<int>
     */
    public function due(DateTimeImmutable $now, int $limit): array
    {
        $rows = Row::all($this->db->table($this->tables->outbox())
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('next_attempt_at', '<=', Timestamps::toDb($now))
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->select(['id']));

        return array_map(static fn(array $row): int => Row::int($row, 'id'), $rows);
    }

    /**
     * Takes a lease on a due row by pushing its next attempt into the future;
     * exactly one worker wins because the UPDATE is conditioned on the row
     * still being due. The lease carries a fresh token and the row as it is
     * under that token, attempt count included.
     */
    public function claim(int $id, DateTimeImmutable $now, int $leaseSeconds): ?Lease
    {
        $token = bin2hex(random_bytes(16));
        $claimed = $this->db->table($this->tables->outbox())
            ->where('id', $id)
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('next_attempt_at', '<=', Timestamps::toDb($now))
            ->update([
                'next_attempt_at' => Timestamps::toDb($now->modify('+' . $leaseSeconds . ' seconds')),
                'attempts' => $this->db->raw('attempts + 1'),
                'lease_token' => $token,
            ]) === 1;
        if (!$claimed) {
            return null;
        }
        $row = Row::first($this->owned($id, $token));

        return $row === null ? null : new Lease($token, $this->hydrate($row));
    }

    /**
     * Outcomes are recorded under the lease that was held while delivering:
     * each returns false, and changes nothing, when the lease has since
     * expired and another worker holds the row (R-002).
     */
    public function markDelivered(Lease $lease, DateTimeImmutable $at): bool
    {
        return $this->owned($lease->pending->id, $lease->token)->update(['delivered_at' => Timestamps::toDb($at), 'last_error' => null, 'lease_token' => null]) === 1;
    }

    public function markRetry(Lease $lease, DateTimeImmutable $next, string $error): bool
    {
        return $this->owned($lease->pending->id, $lease->token)->update(['next_attempt_at' => Timestamps::toDb($next), 'last_error' => $error, 'lease_token' => null]) === 1;
    }

    public function markFailed(Lease $lease, DateTimeImmutable $at, string $error): bool
    {
        return $this->owned($lease->pending->id, $lease->token)->update(['failed_at' => Timestamps::toDb($at), 'last_error' => $error, 'lease_token' => null]) === 1;
    }

    /**
     * Puts a failed row back in the queue, for an operator who fixed the cause.
     */
    public function retry(int $id, DateTimeImmutable $now): bool
    {
        return $this->db->table($this->tables->outbox())
            ->where('id', $id)
            ->whereNotNull('failed_at')
            ->update(['failed_at' => null, 'attempts' => 0, 'next_attempt_at' => Timestamps::toDb($now), 'last_error' => null, 'lease_token' => null]) === 1;
    }

    /**
     * @return int rows removed
     */
    public function prune(DateTimeImmutable $deliveredBefore, DateTimeImmutable $failedBefore): int
    {
        $delivered = $this->db->table($this->tables->outbox())->whereNotNull('delivered_at')->where('delivered_at', '<', Timestamps::toDb($deliveredBefore))->delete();
        $failed = $this->db->table($this->tables->outbox())->whereNotNull('failed_at')->where('failed_at', '<', Timestamps::toDb($failedBefore))->delete();

        return $delivered + $failed;
    }

    private function owned(int $id, string $token): Builder
    {
        return $this->db->table($this->tables->outbox())->where('id', $id)->where('lease_token', $token);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PendingNotification
    {
        return new PendingNotification(
            Row::int($row, 'id'),
            new OutboundNotification(
                new Iri(Row::string($row, 'recipient')),
                Notification::fromJsonLd(Row::string($row, 'document')),
                Timestamps::fromDb($row['created_at']),
                // The identity the SDK assigned, not the row key (R4-003).
                Row::string($row, 'notification_id'),
            ),
            Row::nullableString($row, 'endpoint'),
            Row::int($row, 'attempts'),
        );
    }
}
