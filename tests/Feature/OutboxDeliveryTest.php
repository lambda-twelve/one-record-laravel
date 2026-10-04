<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Laravel\Console\DeliverOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\Console\PruneOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\Console\RetryOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\Notifications\Backoff;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliveryFailed;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliveryRejected;
use LambdaTwelve\OneRecord\Laravel\Notifications\Events\NotificationDelivered;
use LambdaTwelve\OneRecord\Laravel\Notifications\Events\NotificationDeliveryFailed;
use LambdaTwelve\OneRecord\Laravel\Notifications\NotificationDeliverer;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\FakeDeliverer;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\TestLogger;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * From the SDK's fan-out to a delivered notification: the SDK enqueues a row,
 * the provider dispatches a job after commit, the job leases the row and
 * hands it to the host's deliverer, recording the outcome on the row.
 */
#[CoversClass(DeliverNotification::class)]
#[CoversClass(Backoff::class)]
#[CoversClass(DeliverOutboxCommand::class)]
#[CoversClass(PruneOutboxCommand::class)]
#[CoversClass(RetryOutboxCommand::class)]
#[CoversClass(DatabaseNotificationOutbox::class)]
final class OutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeDeliverer $deliverer;

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->deliverer = new FakeDeliverer();
        $this->app()->instance(NotificationDeliverer::class, $this->deliverer);
    }

    /**
     * A partner subscribed to every new piece; publishing one fills the outbox.
     */
    private function publishWithSubscriber(): int
    {
        $holder = $this->app()->make(DataHolder::class);
        $holder->subscribe(new Subscription(new Iri(self::PARTNER), TopicType::Type, Cargo::Piece, [SubscriptionEventType::LogisticsObjectCreated]));
        $holder->create(ObjectBuilder::of(Cargo::Piece)->set(Cargo::goodsDescription, 'Flowers')->build($this->app()->make(ServerConfig::class)->logisticsObjectIri('piece-1')));

        $ids = $this->outbox()->due($this->app()->make(ClockInterface::class)->now(), 10);
        self::assertCount(1, $ids);

        return $ids[0];
    }

    /**
     * What a queue worker does with the job: release the unique lock its
     * dispatch took (the job is unique until processing), then run it.
     */
    private function process(int $id): void
    {
        (new UniqueLock($this->app()->make(Cache::class)))->release(new DeliverNotification($id));
        (new DeliverNotification($id))->handle($this->app());
    }

    private function outbox(): DatabaseNotificationOutbox
    {
        $outbox = $this->app()->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);

        return $outbox;
    }

    public function testTheSdkFanOutEnqueuesARowAndDispatchesAJobAfterCommit(): void
    {
        Bus::fake();

        $id = $this->publishWithSubscriber();

        $pending = $this->outbox()->find($id);
        self::assertNotNull($pending);
        self::assertSame(self::PARTNER, $pending->outbound->recipient->value);
        self::assertSame('https://partner.example/notifications', $pending->endpoint);
        self::assertSame(NotificationEventType::LogisticsObjectCreated, $pending->outbound->notification->eventType);
        Bus::assertDispatched(DeliverNotification::class, static fn(DeliverNotification $job): bool => $job->outboxId === $id);
    }

    /**
     * The outbox hands the row to the dispatcher only once the enqueuing
     * transaction has committed; a transaction that rolls back after the
     * enqueue discards that callback with the row (AR3-002: the enqueue
     * itself has to happen for this to mean anything).
     */
    public function testARolledBackEnqueueQueuesNoJob(): void
    {
        Bus::fake();
        $outbox = $this->outbox();
        $now = $this->app()->make(ClockInterface::class)->now();

        // The closure always throws (PHPStan knows, so no fail() after the call); the catch checks it was ours.
        try {
            $this->app()->make(ConnectionInterface::class)->transaction(static function () use ($outbox, $now): void {
                $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'rolled-back'));
                self::assertCount(1, $outbox->due($now, 10), 'the row is in, inside the transaction');
                Bus::assertNothingDispatched();

                throw new RuntimeException('roll back after the enqueue');
            });
        } catch (RuntimeException $e) {
            self::assertSame('roll back after the enqueue', $e->getMessage());
        }

        self::assertSame([], $outbox->due($now, 10), 'the row went with the transaction');
        Bus::assertNothingDispatched();
    }

    public function testNoJobIsDispatchedWhenDispatchIsOff(): void
    {
        $this->config()->set('one-record.outbox.dispatch', 'none');
        Bus::fake();

        $this->publishWithSubscriber();

        Bus::assertNothingDispatched();
    }

    public function testASuccessfulDeliveryMarksTheRow(): void
    {
        Bus::fake();
        Event::fake([NotificationDelivered::class]);
        $id = $this->publishWithSubscriber();

        $this->process($id);

        self::assertCount(1, $this->deliverer->delivered);
        self::assertSame($id, $this->deliverer->delivered[0]->id);
        self::assertSame([], $this->outbox()->due($this->app()->make(ClockInterface::class)->now()->modify('+1 day'), 10));
        Event::assertDispatched(NotificationDelivered::class);

        $this->process($id);
        self::assertCount(1, $this->deliverer->delivered, 'a delivered row is never delivered twice');
    }

    public function testAFailedAttemptBacksOffAndRequeues(): void
    {
        Bus::fake();
        Event::fake([NotificationDeliveryFailed::class]);
        $id = $this->publishWithSubscriber();
        $this->deliverer->failWith(new DeliveryFailed('connection refused'));
        $now = $this->app()->make(ClockInterface::class)->now();

        $this->process($id);

        self::assertSame([], $this->deliverer->delivered);
        self::assertSame(1, $this->outbox()->find($id)?->attempts);
        self::assertSame([], $this->outbox()->due($now->modify('+59 seconds'), 10));
        self::assertSame([$id], $this->outbox()->due($now->modify('+61 seconds'), 10), 'first retry after a minute');
        Bus::assertDispatched(DeliverNotification::class, static fn(DeliverNotification $job): bool => $job->outboxId === $id && $job->delay !== null);
        Event::assertDispatched(NotificationDeliveryFailed::class, static fn(NotificationDeliveryFailed $e): bool => $e->error === 'connection refused' && !$e->final);

        // Second attempt, once due: a longer wait.
        $this->travelTo($now->modify('+2 minutes'));
        $this->deliverer->failWith(new DeliveryFailed('still down'));
        $this->process($id);
        self::assertSame(2, $this->outbox()->find($id)?->attempts);
        self::assertSame([], $this->outbox()->due($now->modify('+2 minutes')->modify('+299 seconds'), 10));
        self::assertSame([$id], $this->outbox()->due($now->modify('+2 minutes')->modify('+301 seconds'), 10));
    }

    public function testAttemptsAreGivenUpAtTheConfiguredMaximum(): void
    {
        Bus::fake();
        Event::fake([NotificationDeliveryFailed::class]);
        $this->config()->set('one-record.outbox.max_attempts', 1);
        $id = $this->publishWithSubscriber();
        $this->deliverer->failWith(new DeliveryFailed('connection refused'));

        $this->process($id);

        self::assertSame([], $this->outbox()->due($this->app()->make(ClockInterface::class)->now()->modify('+10 days'), 10), 'a given-up row is no longer due');
        Bus::assertDispatchedTimes(DeliverNotification::class, 1);
        Event::assertDispatched(NotificationDeliveryFailed::class, static fn(NotificationDeliveryFailed $e): bool => $e->final);
    }

    public function testARejectedDeliveryFailsImmediately(): void
    {
        Bus::fake();
        Event::fake([NotificationDeliveryFailed::class]);
        $id = $this->publishWithSubscriber();
        $this->deliverer->failWith(new DeliveryRejected('no endpoint for recipient'));

        $this->process($id);

        self::assertSame([], $this->outbox()->due($this->app()->make(ClockInterface::class)->now()->modify('+10 days'), 10));
        Bus::assertDispatchedTimes(DeliverNotification::class, 1);
        Event::assertDispatched(NotificationDeliveryFailed::class, static fn(NotificationDeliveryFailed $e): bool => $e->final && $e->error === 'no endpoint for recipient');

        $retry = $this->artisan('one-record:outbox:retry', ['id' => $id]);
        self::assertInstanceOf(PendingCommand::class, $retry);
        $retry->assertSuccessful()->run();
        self::assertSame([$id], $this->outbox()->due($this->app()->make(ClockInterface::class)->now(), 10));
        Bus::assertDispatchedTimes(DeliverNotification::class, 2);
    }

    public function testALeasedRowIsLeftToTheWorkerHoldingIt(): void
    {
        Bus::fake();
        $id = $this->publishWithSubscriber();
        self::assertNotNull($this->outbox()->claim($id, $this->app()->make(ClockInterface::class)->now(), 120));

        $this->process($id);

        self::assertSame([], $this->deliverer->delivered);
    }

    public function testTheDeliverCommandProcessesDueRowsInlineOrDispatchesThem(): void
    {
        Bus::fake();
        $id = $this->publishWithSubscriber();

        $dispatch = $this->artisan('one-record:outbox:deliver');
        self::assertInstanceOf(PendingCommand::class, $dispatch);
        $dispatch->assertSuccessful()->run();
        // The job queued by the fan-out still holds the row's unique lock: a sweep queues no second one.
        Bus::assertDispatchedTimes(DeliverNotification::class, 1);

        $inline = $this->artisan('one-record:outbox:deliver', ['--inline' => true]);
        self::assertInstanceOf(PendingCommand::class, $inline);
        $inline->expectsOutputToContain('1 due notification(s) processed')->assertSuccessful()->run();
        self::assertSame([$id], array_map(static fn($p): int => $p->id, $this->deliverer->delivered));

        $prune = $this->artisan('one-record:outbox:prune', ['--delivered-days' => 1]);
        self::assertInstanceOf(PendingCommand::class, $prune);
        $prune->assertSuccessful()->run();
        self::assertNotNull($this->outbox()->find($id), 'delivered just now is not a day old');
    }

    public function testWithoutADelivererBindingTheRowWaitsAndAWarningIsLogged(): void
    {
        Bus::fake();
        $id = $this->publishWithSubscriber();
        $this->app()->forgetInstance(NotificationDeliverer::class);
        $this->app()->offsetUnset(NotificationDeliverer::class);
        $logger = new TestLogger();
        $this->app()->instance(OneRecordServiceProvider::LOGGER, $logger);

        $this->process($id);

        self::assertSame([$id], $this->outbox()->due($this->app()->make(ClockInterface::class)->now(), 10), 'still due, nothing lost');
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString(NotificationDeliverer::class, $logger->records[0]['message']);
    }
}
