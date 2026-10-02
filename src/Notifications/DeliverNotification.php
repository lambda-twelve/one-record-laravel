<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LambdaTwelve\OneRecord\Laravel\Notifications\Events\NotificationDelivered;
use LambdaTwelve\OneRecord\Laravel\Notifications\Events\NotificationDeliveryFailed;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LogicException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Delivers one outbox row. The row, not the queue, is the source of truth:
 * the job leases the row, hands it to the NotificationDeliverer, and records
 * the outcome, so a job lost by the queue only delays a notification until
 * `one-record:outbox:deliver` picks the row up again. Retries are scheduled
 * from the row's attempt count with Backoff.
 */
final class DeliverNotification implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

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
        $now = $clock->now();

        $pending = $outbox->find($this->outboxId);
        if ($pending === null || !$outbox->claim($this->outboxId, $now, self::int($config->get('one-record.outbox.lease_seconds'), 120))) {
            return;   // delivered, failed, not yet due, or another worker holds it
        }
        $attempt = $pending->attempts + 1;

        try {
            $app->make(NotificationDeliverer::class)->deliver($pending);
        } catch (DeliveryRejected $e) {
            $outbox->markFailed($this->outboxId, $clock->now(), $e->getMessage());
            $events->dispatch(new NotificationDeliveryFailed($pending, $e->getMessage(), true));

            return;
        } catch (Throwable $e) {   // DeliveryFailed, or anything unexpected: worth another try
            $final = $attempt >= self::int($config->get('one-record.outbox.max_attempts'), 10);
            if ($final) {
                $outbox->markFailed($this->outboxId, $clock->now(), $e->getMessage());
            } else {
                $next = $clock->now()->modify('+' . Backoff::seconds($attempt) . ' seconds');
                $outbox->markRetry($this->outboxId, $next, $e->getMessage());
                if ($config->get('one-record.outbox.dispatch') === 'queue') {
                    $app->make(Bus::class)->dispatch(self::forRow($this->outboxId, $config)->delay($next));
                }
            }
            $events->dispatch(new NotificationDeliveryFailed($pending, $e->getMessage(), $final));

            return;
        }

        $outbox->markDelivered($this->outboxId, $clock->now());
        $events->dispatch(new NotificationDelivered($pending));
    }

    /**
     * A job for a row, on the configured connection and queue, released only
     * after the enqueuing transaction commits.
     */
    public static function forRow(int $outboxId, Repository $config): self
    {
        $job = (new self($outboxId))->afterCommit();
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

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
