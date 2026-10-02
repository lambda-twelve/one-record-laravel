<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
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
 * lose a notification.
 */
final class DatabaseNotificationOutbox implements NotificationOutbox
{
    /**
     * @param ?Closure(int): void $onEnqueued called with the new row id, e.g. to dispatch a delivery job after commit
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
        private readonly ?Closure $onEnqueued = null,
    ) {}

    public function enqueue(OutboundNotification $notification): void
    {
        $id = $this->db->table($this->tables->outbox())->insertGetId([
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
            ($this->onEnqueued)($id);
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
     * still being due.
     */
    public function claim(int $id, DateTimeImmutable $now, int $leaseSeconds): bool
    {
        return $this->db->table($this->tables->outbox())
            ->where('id', $id)
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('next_attempt_at', '<=', Timestamps::toDb($now))
            ->update(['next_attempt_at' => Timestamps::toDb($now->modify('+' . $leaseSeconds . ' seconds')), 'attempts' => $this->db->raw('attempts + 1')]) === 1;
    }

    public function markDelivered(int $id, DateTimeImmutable $at): void
    {
        $this->db->table($this->tables->outbox())->where('id', $id)->update(['delivered_at' => Timestamps::toDb($at), 'last_error' => null]);
    }

    public function markRetry(int $id, DateTimeImmutable $next, string $error): void
    {
        $this->db->table($this->tables->outbox())->where('id', $id)->update(['next_attempt_at' => Timestamps::toDb($next), 'last_error' => $error]);
    }

    public function markFailed(int $id, DateTimeImmutable $at, string $error): void
    {
        $this->db->table($this->tables->outbox())->where('id', $id)->update(['failed_at' => Timestamps::toDb($at), 'last_error' => $error]);
    }

    /**
     * Puts a failed row back in the queue, for an operator who fixed the cause.
     */
    public function retry(int $id, DateTimeImmutable $now): bool
    {
        return $this->db->table($this->tables->outbox())
            ->where('id', $id)
            ->whereNotNull('failed_at')
            ->update(['failed_at' => null, 'attempts' => 0, 'next_attempt_at' => Timestamps::toDb($now), 'last_error' => null]) === 1;
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
            ),
            Row::nullableString($row, 'endpoint'),
            Row::int($row, 'attempts'),
        );
    }
}
