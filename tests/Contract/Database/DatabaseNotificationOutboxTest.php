<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The host side of the outbox: due rows, leases, outcomes and pruning. What
 * the SDK asks of every outbox is checked by its shipped contract in
 * tests/Contract/Sdk.
 */
#[CoversClass(DatabaseNotificationOutbox::class)]
final class DatabaseNotificationOutboxTest extends DatabaseTestCase
{
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

    private function databaseOutbox(): DatabaseNotificationOutbox
    {
        $outbox = $this->outbox();
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);

        return $outbox;
    }

    private int $sequence = 0;

    private function enqueueOne(string $created = '2026-10-02T12:00:00.000Z'): int
    {
        // Every notification has its own id, as the SDK's generator guarantees; the table enforces it.
        $this->outbox()->enqueue(new OutboundNotification(new Iri('https://partner.example/logistics-objects/partner'), new Notification(NotificationEventType::LogisticsObjectCreated, Documents::iri('p1')), Documents::at($created), 'n-' . ++$this->sequence));
        $ids = $this->databaseOutbox()->due(Documents::at('2099-01-01T00:00:00.000Z'), 100);

        return $ids[\count($ids) - 1];
    }

    public function testDueRowsAreLeasedOnceAndTheirOutcomeRecordedUnderTheLease(): void
    {
        $outbox = $this->databaseOutbox();
        $first = $this->enqueueOne('2026-10-02T12:00:00.000Z');
        $second = $this->enqueueOne('2026-10-02T12:01:00.000Z');
        $now = Documents::at('2026-10-02T12:05:00.000Z');

        self::assertSame([$first, $second], $outbox->due($now, 10));
        self::assertSame([$first], $outbox->due($now, 1));
        self::assertSame([], $outbox->due(Documents::at('2026-10-02T11:00:00.000Z'), 10), 'nothing is due before it was created');

        $lease = $outbox->claim($first, $now, 120);
        self::assertNotNull($lease);
        self::assertSame($first, $lease->pending->id);
        self::assertSame(1, $lease->pending->attempts, 'the claim counts the attempt and hands the row back');
        self::assertNull($outbox->claim($first, $now, 120), 'a leased row cannot be claimed again');
        self::assertSame([$second], $outbox->due($now, 10));
        self::assertSame([$second, $first], $outbox->due($now->modify('+121 seconds'), 10), 'the lease expires; due rows come in due-time order');

        self::assertTrue($outbox->markRetry($lease, $now->modify('+1 hour'), 'connection refused'));
        self::assertFalse($outbox->markRetry($lease, $now->modify('+1 hour'), 'again'), 'an outcome releases the lease');
        self::assertSame([$second], $outbox->due($now->modify('+30 minutes'), 10));
        self::assertSame([$second, $first], $outbox->due($now->modify('+2 hours'), 10));

        $lease = $outbox->claim($first, $now->modify('+2 hours'), 120);
        self::assertNotNull($lease);
        self::assertSame(2, $lease->pending->attempts);
        self::assertTrue($outbox->markDelivered($lease, $now->modify('+2 hours')));
        self::assertSame([$second], $outbox->due($now->modify('+2 hours'), 10));
        self::assertNull($outbox->claim($first, $now->modify('+3 hours'), 120), 'delivered rows are never claimed');

        $lease = $outbox->claim($second, $now, 120);
        self::assertNotNull($lease);
        self::assertTrue($outbox->markFailed($lease, $now, 'gone for good'));
        self::assertSame([], $outbox->due($now->modify('+3 hours'), 10));
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
        $leaseDelivered = $outbox->claim($delivered, Documents::at('2026-10-02T13:00:00.000Z'), 60);
        $leaseFailed = $outbox->claim($failed, Documents::at('2026-10-02T13:00:00.000Z'), 60);
        self::assertNotNull($leaseDelivered);
        self::assertNotNull($leaseFailed);
        $outbox->markDelivered($leaseDelivered, Documents::at('2026-09-01T00:00:00.000Z'));
        $outbox->markFailed($leaseFailed, Documents::at('2026-09-01T00:00:00.000Z'), 'x');

        self::assertSame(0, $outbox->prune(Documents::at('2026-08-01T00:00:00.000Z'), Documents::at('2026-08-01T00:00:00.000Z')));
        self::assertSame(2, $outbox->prune(Documents::at('2026-10-01T00:00:00.000Z'), Documents::at('2026-10-01T00:00:00.000Z')));
        self::assertNull($outbox->find($delivered));
        self::assertNull($outbox->find($failed));
        self::assertNotNull($outbox->find($pending));
    }
}
