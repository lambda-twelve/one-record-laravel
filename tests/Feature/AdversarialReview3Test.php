<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Testing\PendingCommand;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\DatabaseConnection;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * The probe of the third adversarial review (2026-10-04, against f37f847),
 * kept as a regression test. The ONE Record tables live on a storage
 * connection of their own (an in-memory SQLite database), the application's
 * connection is the test database, and the delivery job goes to a real
 * database queue on that application connection, configured with
 * after_commit: the layout in which Laravel can defer a queue submission to
 * a transaction other than the one that enqueued the notification.
 *
 * What remains by design, and the fourth review confirmed: a database queue
 * on a connection whose own transaction is open writes the job row inside
 * that transaction, so an application rollback takes the job with it while
 * the unique lock stays until it expires (an hour); the first sweep after
 * that queues the row again. The second test pins exactly that.
 */
#[CoversClass(DeliverNotification::class)]
#[CoversClass(DatabaseNotificationOutbox::class)]
final class AdversarialReview3Test extends TestCase
{
    private const string STORAGE = 'review_storage';
    private const string JOBS = 'review_jobs';

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('database.connections.' . self::STORAGE, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $config->set('one-record.storage.connection', self::STORAGE);
        $config->set('one-record.outbox.dispatch', 'queue');
        $config->set('one-record.outbox.connection', 'review');
        $config->set('queue.connections.review', ['driver' => 'database', 'connection' => DatabaseConnection::name(), 'table' => self::JOBS, 'queue' => 'default', 'retry_after' => 90, 'after_commit' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $files = glob(__DIR__ . '/../../database/migrations/*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            $migration = require $file;
            \assert($migration instanceof Migration);
            $step = [$migration, 'up'];
            \assert(\is_callable($step));
            $step();
        }
        $this->application()->getSchemaBuilder()->dropIfExists(self::JOBS);
        $this->application()->getSchemaBuilder()->create(self::JOBS, static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        $application = $this->application();
        while ($application->transactionLevel() > 0) {
            $application->rollBack();
        }
        $application->getSchemaBuilder()->dropIfExists(self::JOBS);
        parent::tearDown();
    }

    /**
     * AR3-001. With a transaction open on the application connection, the
     * queue's after_commit used to defer the submission to that
     * transaction's commit, where a failure left the unique lock behind and
     * every sweep skipped the row. The job now refuses that deferral: the
     * submission happens when the storage transaction commits, a failure
     * there gives the lock back, and the next sweep queues the row.
     */
    public function testAQueueConfiguredWithAfterCommitCannotStrandTheLock(): void
    {
        $app = $this->app();
        $application = $this->application();
        $storage = $this->storage();
        $outbox = $this->outbox();
        $now = $app->make(ClockInterface::class)->now();
        $events = $app->make(Dispatcher::class);
        $events->listen(JobQueueing::class, static function (): void {
            throw new RuntimeException('queue unavailable');
        });

        $application->beginTransaction();
        try {
            $storage->transaction(static function () use ($outbox, $now): void {
                $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'deferred-queue-failure'));
            });
            self::fail('the submission, and so its failure, happens when the storage transaction commits');
        } catch (RuntimeException $e) {
            self::assertSame('queue unavailable', $e->getMessage());
        }
        self::assertSame(0, $storage->transactionLevel(), 'the outbox transaction committed');
        [$id] = $outbox->due($now, 1);
        self::assertSame(0, $application->table(self::JOBS)->count(), 'nothing was queued');

        $events->forget(JobQueueing::class);
        $sweep = $this->artisan('one-record:outbox:deliver');
        self::assertInstanceOf(PendingCommand::class, $sweep);
        $sweep->assertSuccessful()->run();

        self::assertSame(1, $application->table(self::JOBS)->count(), 'the sweep queued the row, with the application transaction still open');
        DeliverNotification::dispatchFor($app, $id);
        self::assertSame(1, $application->table(self::JOBS)->count(), 'and that job holds the lock');
        $application->commit();
        self::assertSame(1, $application->table(self::JOBS)->count());
    }

    /**
     * Review 4's probe. The job row written through the application connection
     * goes with that connection's rollback; the notification stays committed
     * on the storage connection; sweeps are refused by the lock the lost job
     * left until it expires, and the first sweep after that queues the row.
     * A delay of up to the lock lifetime plus the sweep interval, not a loss.
     */
    public function testAnApplicationRollbackDelaysTheRowUntilTheLockExpires(): void
    {
        $app = $this->app();
        $application = $this->application();
        $storage = $this->storage();
        $outbox = $this->outbox();
        $now = $app->make(ClockInterface::class)->now();
        $this->travelTo($now);

        $application->beginTransaction();
        $storage->transaction(static function () use ($outbox, $now): void {
            $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'rolled-back-job'));
        });
        [$id] = $outbox->due($now, 1);
        self::assertSame(1, $application->table(self::JOBS)->count(), 'the job row sits inside the application transaction');
        $application->rollBack();
        self::assertSame(0, $application->table(self::JOBS)->count(), 'and goes with its rollback');
        self::assertSame('rolled-back-job', $outbox->find($id)?->outbound->id, 'the notification itself is committed');

        $this->sweep();
        self::assertSame(0, $application->table(self::JOBS)->count(), 'the lock the lost job left refuses the sweep');
        $this->travelTo($now->modify('+3599 seconds'));
        $this->sweep();
        self::assertSame(0, $application->table(self::JOBS)->count(), 'still, just before the lock expires');
        $this->travelTo($now->modify('+3601 seconds'));
        $this->sweep();
        self::assertSame(1, $application->table(self::JOBS)->count(), 'the first sweep after the lock expired queues the row');
        self::assertSame([$id], $outbox->due($now->modify('+3601 seconds'), 1), 'the row waits for that job');
    }

    private function sweep(): void
    {
        $sweep = $this->artisan('one-record:outbox:deliver');
        self::assertInstanceOf(PendingCommand::class, $sweep);
        $sweep->assertSuccessful()->run();
    }

    private function application(): Connection
    {
        $connection = $this->app()->make(ConnectionResolverInterface::class)->connection(DatabaseConnection::name());
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function storage(): Connection
    {
        $connection = $this->app()->make(ConnectionResolverInterface::class)->connection(self::STORAGE);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function outbox(): DatabaseNotificationOutbox
    {
        $outbox = $this->app()->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);

        return $outbox;
    }
}
