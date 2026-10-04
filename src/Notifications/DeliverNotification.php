<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications;

use DateTimeInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LambdaTwelve\OneRecord\Client\DeliveryVerdict;
use LambdaTwelve\OneRecord\Laravel\Notifications\Events\NotificationDelivered;
use LambdaTwelve\OneRecord\Laravel\Notifications\Events\NotificationDeliveryFailed;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Lease;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LogicException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Delivers one outbox row. The row, not the queue, is the source of truth:
 * the job leases the row, hands it to the NotificationDeliverer, and records
 * the outcome under that lease, so a job lost by the queue only delays a
 * notification until `one-record:outbox:deliver` picks the row up again, and
 * a worker whose lease expired mid-delivery cannot overwrite the outcome of
 * the worker that took over. Retries are scheduled from the row's attempt
 * count with Backoff. Delivery is at least once; recipients deduplicate on
 * the notification id. A deliverer says what it knows by throwing
 * DeliveryFailed (retry) or DeliveryRejected (final); anything else is
 * classified by the SDK's DeliveryVerdict, the same way every host does.
 *
 * One queued job per row: the unique lock is taken when the job is queued
 * (through dispatchFor(), never the bus contract directly, which skips it),
 * given back if the queue refuses the job, and released when processing
 * starts, so the retry a running job dispatches for its own row is never
 * refused.
 */
final class DeliverNotification implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 1;

    /**
     * How long a queued job keeps its row from being queued again. Bounded so
     * that a job the queue lost before processing cannot block its row for
     * good; a sweep after this long may queue a second job for a lagging row,
     * which then finds the row leased or delivered and does nothing.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(Container $app): void
    {
        $outbox = $app->make(NotificationOutbox::class);
        if (!$outbox instanceof DatabaseNotificationOutbox) {
            throw new LogicException('Outbox delivery needs the database storage driver.');
        }
        if (!$app->bound(NotificationDeliverer::class)) {
            // Leave the row due rather than fail the request or the worker: once the host binds a
            // deliverer, one-record:outbox:deliver picks everything up. Nothing is lost, only delayed.
            OneRecordServiceProvider::logger($app)->warning('ONE Record notification {id} is waiting: bind ' . NotificationDeliverer::class . ' to deliver notifications (it decides where each recipient\'s /notifications endpoint is and which credentials to use).', ['id' => $this->outboxId]);

            return;
        }
        $clock = $app->make(ClockInterface::class);
        $config = $app->make(Repository::class);
        $events = $app->make(Events::class);

        $lease = $outbox->claim($this->outboxId, $clock->now(), self::int($config->get('one-record.outbox.lease_seconds'), 120));
        if ($lease === null) {
            return;   // delivered, failed, not yet due, or another worker holds it
        }
        $pending = $lease->pending;
        $attempt = $pending->attempts;   // the claim counted this attempt

        try {
            $app->make(NotificationDeliverer::class)->deliver($pending);
        } catch (DeliveryRejected $e) {
            if ($this->recorded($app, $lease, $outbox->markFailed($lease, $clock->now(), $e->getMessage()))) {
                $events->dispatch(new NotificationDeliveryFailed($pending, $e->getMessage(), true));
            }

            return;
        } catch (Throwable $e) {
            // DeliveryFailed is the deliverer's own verdict; for anything else the SDK decides: transport
            // failures and 5xx / 408 / 429 are retried, every other answer or a sending-side defect is final.
            $retry = $e instanceof DeliveryFailed || DeliveryVerdict::of($e) === DeliveryVerdict::Retry;
            $final = !$retry || $attempt >= self::int($config->get('one-record.outbox.max_attempts'), 10);
            if ($final) {
                $recorded = $outbox->markFailed($lease, $clock->now(), $e->getMessage());
            } else {
                $next = $clock->now()->modify('+' . Backoff::seconds($attempt) . ' seconds');
                $recorded = $outbox->markRetry($lease, $next, $e->getMessage());
                if ($recorded && $config->get('one-record.outbox.dispatch') === 'queue') {
                    self::dispatchFor($app, $this->outboxId, $next);
                }
            }
            if ($this->recorded($app, $lease, $recorded)) {
                $events->dispatch(new NotificationDeliveryFailed($pending, $e->getMessage(), $final));
            }

            return;
        }

        if ($this->recorded($app, $lease, $outbox->markDelivered($lease, $clock->now()))) {
            $events->dispatch(new NotificationDelivered($pending));
        }
    }

    /**
     * Queues a job for a row, on the configured connection and queue, unless
     * one is queued already. The unique-job lock is taken here rather than by
     * Laravel's PendingDispatch so that a queue that refuses the job (an
     * outage, a listener that throws) gives the lock back at once: a lock
     * left behind would keep every sweep from queuing the row again until it
     * expired (AR2-002). The outbox calls this only after the enqueuing
     * transaction has committed.
     */
    public static function dispatchFor(Container $app, int $outboxId, ?DateTimeInterface $delayUntil = null): void
    {
        $job = self::forRow($outboxId, $app->make(Repository::class));
        if ($delayUntil !== null) {
            $job->delay($delayUntil);
        }
        $lock = new UniqueLock($app->make(Cache::class));
        if (!$lock->acquire($job)) {
            return;   // a job for this row is queued and not yet started
        }
        try {
            $app->make(Bus::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);

            throw $e;
        }
    }

    /**
     * A job for a row, on the configured connection and queue. Commit timing
     * is the outbox's (it calls dispatchFor() once the enqueuing transaction
     * has committed), so the job says beforeCommit() explicitly: a queue
     * connection configured with after_commit would otherwise defer the
     * submission again, to the commit of whatever other transaction the
     * application has open, past the point where dispatchFor() can give the
     * unique lock back if that submission fails (AR3-001).
     */
    public static function forRow(int $outboxId, Repository $config): self
    {
        $job = (new self($outboxId))->beforeCommit();
        $connection = $config->get('one-record.outbox.connection');
        $queue = $config->get('one-record.outbox.queue');
        if (\is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if (\is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        return $job;
    }

    /**
     * An outcome that was not recorded belongs to a lease that expired while
     * delivering; the worker now holding the row records its own, and the
     * recipient may see the notification twice.
     */
    private function recorded(Container $app, Lease $lease, bool $recorded): bool
    {
        if (!$recorded) {
            OneRecordServiceProvider::logger($app)->warning('ONE Record notification {id} ({notification}): the delivery lease expired before the outcome was recorded; another worker holds the row now and its outcome counts.', ['id' => $this->outboxId, 'notification' => $lease->pending->outbound->id]);
        }

        return $recorded;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
