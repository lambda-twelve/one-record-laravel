<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\NotificationOutboxContract;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseNotificationOutbox::class)]
final class DatabaseNotificationOutboxTest extends DatabaseTestCase
{
    use NotificationOutboxContract;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // This case is about the store; delivery jobs have their own test.
        $app->make(\Illuminate\Contracts\Config\Repository::class)->set('one-record.outbox.dispatch', 'none');
    }

    protected function outbox(): NotificationOutbox
    {
        return $this->app()->make(NotificationOutbox::class);
    }

    protected function enqueued(): array
    {
        $outbox = $this->databaseOutbox();
        $out = [];
        foreach ($outbox->due(Documents::at('2099-01-01T00:00:00.000Z'), 100) as $id) {
            $pending = $outbox->find($id);
            self::assertNotNull($pending);
            $out[] = $pending->outbound;
        }

        return $out;
    }

    private function databaseOutbox(): DatabaseNotificationOutbox
    {
        $outbox = $this->outbox();
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);

        return $outbox;
    }

    private function enqueueOne(string $created = '2026-10-02T12:00:00.000Z'): int
    {
        $this->outbox()->enqueue(new OutboundNotification(new Iri('https://partner.example/logistics-objects/partner'), new Notification(NotificationEventType::LogisticsObjectCreated, Documents::iri('p1')), Documents::at($created), 'n-' . $created));
        $ids = $this->databaseOutbox()->due(Documents::at('2099-01-01T00:00:00.000Z'), 100);

        return $ids[\count($ids) - 1];
    }

    public function testDueRowsAreLeasedOnceAndTheirOutcomeRecorded(): void
    {
        $outbox = $this->databaseOutbox();
        $first = $this->enqueueOne('2026-10-02T12:00:00.000Z');
        $second = $this->enqueueOne('2026-10-02T12:01:00.000Z');
        $now = Documents::at('2026-10-02T12:05:00.000Z');

        self::assertSame([$first, $second], $outbox->due($now, 10));
        self::assertSame([$first], $outbox->due($now, 1));
        self::assertSame([], $outbox->due(Documents::at('2026-10-02T11:00:00.000Z'), 10), 'nothing is due before it was created');

        self::assertTrue($outbox->claim($first, $now, 120));
        self::assertFalse($outbox->claim($first, $now, 120), 'a leased row cannot be claimed again');
        self::assertSame([$second], $outbox->due($now, 10));
        self::assertSame(1, $outbox->find($first)?->attempts);
        self::assertSame([$second, $first], $outbox->due($now->modify('+121 seconds'), 10), 'the lease expires; due rows come in due-time order');

        $outbox->markRetry($first, $now->modify('+1 hour'), 'connection refused');
        self::assertSame([$second], $outbox->due($now->modify('+30 minutes'), 10));
        self::assertSame([$second, $first], $outbox->due($now->modify('+2 hours'), 10));

        $outbox->markDelivered($first, $now);
        self::assertSame([$second], $outbox->due($now->modify('+2 hours'), 10));
        self::assertFalse($outbox->claim($first, $now->modify('+2 hours'), 120));

        $outbox->markFailed($second, $now, 'gone for good');
        self::assertSame([], $outbox->due($now->modify('+2 hours'), 10));
        self::assertTrue($outbox->retry($second, $now));
        self::assertFalse($outbox->retry($first, $now), 'only failed rows can be retried');
        self::assertSame([$second], $outbox->due($now, 10));
        self::assertSame(0, $outbox->find($second)?->attempts);

        self::assertNull($outbox->find(999_999));
    }

    public function testPruneRemovesOldDeliveredAndFailedRows(): void
    {
        $outbox = $this->databaseOutbox();
        $delivered = $this->enqueueOne();
        $failed = $this->enqueueOne();
        $pending = $this->enqueueOne();
        $outbox->markDelivered($delivered, Documents::at('2026-09-01T00:00:00.000Z'));
        $outbox->markFailed($failed, Documents::at('2026-09-01T00:00:00.000Z'), 'x');

        self::assertSame(0, $outbox->prune(Documents::at('2026-08-01T00:00:00.000Z'), Documents::at('2026-08-01T00:00:00.000Z')));
        self::assertSame(2, $outbox->prune(Documents::at('2026-10-01T00:00:00.000Z'), Documents::at('2026-10-01T00:00:00.000Z')));
        self::assertNull($outbox->find($delivered));
        self::assertNull($outbox->find($failed));
        self::assertNotNull($outbox->find($pending));
    }
}
