<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
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
 * The probes of the second adversarial review (2026-10-04, against 46cd12a),
 * kept as regression tests. Both need a schema they control rather than
 * RefreshDatabase: one upgrades it, the other queues through a real database
 * queue, whose table creation would commit a test transaction on MySQL. The
 * package tables are recreated empty before and after each test, which is
 * the state the RefreshDatabase-based tests expect to find.
 */
#[CoversClass(DeliverNotification::class)]
#[CoversClass(DatabaseNotificationOutbox::class)]
final class AdversarialReview2Test extends TestCase
{
    private const string CREATE = __DIR__ . '/../../database/migrations/2026_01_01_000000_create_one_record_tables.php';
    private const string LEASE = __DIR__ . '/../../database/migrations/2026_10_04_000000_add_lease_token_to_one_record_outbox.php';
    private const string JOBS = 'review_jobs';

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('one-record.outbox.dispatch', 'none');
        $config->set('one-record.outbox.connection', 'review');
        $config->set('queue.connections.review', ['driver' => 'database', 'connection' => DatabaseConnection::name(), 'table' => self::JOBS, 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateSchema();
    }

    protected function tearDown(): void
    {
        $this->recreateSchema();
        $this->db()->getSchemaBuilder()->dropIfExists(self::JOBS);
        parent::tearDown();
    }

    /**
     * AR2-001. A database migrated before the lease existed must gain the
     * column from `migrate`, keep its rows, and serve claims; a fresh one
     * runs both migrations; running the forward one twice changes nothing.
     */
    public function testAnExistingInstallationGainsTheLeaseColumnFromMigrate(): void
    {
        $schema = $this->db()->getSchemaBuilder();
        $outbox = $this->outbox();
        $now = $this->app()->make(ClockInterface::class)->now();

        // The schema as shipped before the lease, with a row already in it.
        self::migration(self::LEASE, 'down');
        self::assertFalse($schema->hasColumn('one_record_outbox', 'lease_token'));
        $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'upgrade'));
        $repository = $this->app()->make('migration.repository');   // bound by name: the migrator's, console-only
        self::assertInstanceOf(MigrationRepositoryInterface::class, $repository);
        if (!$repository->repositoryExists()) {
            $repository->createRepository();
        }
        foreach (['2026_01_01_000000_create_one_record_tables', '2026_10_04_000000_add_lease_token_to_one_record_outbox'] as $name) {
            $repository->delete((object) ['migration' => $name]);
        }
        $repository->log('2026_01_01_000000_create_one_record_tables', 1);

        $migrate = $this->artisan('migrate', ['--force' => true]);
        self::assertInstanceOf(PendingCommand::class, $migrate);
        $migrate->assertSuccessful()->run();

        self::assertTrue($schema->hasColumn('one_record_outbox', 'lease_token'), 'migrate added the column to the existing table');
        self::assertContains('2026_10_04_000000_add_lease_token_to_one_record_outbox', $repository->getRan());
        [$id] = $outbox->due($now, 1);
        $lease = $outbox->claim($id, $now, 120);
        self::assertNotNull($lease, 'the row written before the upgrade is claimed under a lease');
        self::assertSame('upgrade', $lease->pending->outbound->id);
        self::assertTrue($outbox->markDelivered($lease, $now));

        self::migration(self::LEASE, 'up');
        self::assertTrue($schema->hasColumn('one_record_outbox', 'lease_token'), 'the forward migration is idempotent');
    }

    /**
     * AR2-002. A queue that refuses the job (here a JobQueueing listener that
     * throws, on a real database queue) used to leave the unique-job lock
     * behind, so the next sweep skipped the row until the lock expired.
     */
    public function testAQueueFailureGivesTheLockBackSoTheNextSweepQueuesTheRow(): void
    {
        $db = $this->db();
        $db->getSchemaBuilder()->create(self::JOBS, static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $outbox = $this->outbox();
        $now = $this->app()->make(ClockInterface::class)->now();
        $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'queue-recovery'));
        [$id] = $outbox->due($now, 1);
        $this->config()->set('one-record.outbox.dispatch', 'queue');
        $events = $this->app()->make(Dispatcher::class);
        $events->listen(JobQueueing::class, static function (): void {
            throw new RuntimeException('queue unavailable');
        });

        try {
            DeliverNotification::dispatchFor($this->app(), $id);
            self::fail('the queue failure propagates');
        } catch (RuntimeException $e) {
            self::assertSame('queue unavailable', $e->getMessage());
        }
        self::assertSame(0, $db->table(self::JOBS)->count(), 'nothing was queued');

        $events->forget(JobQueueing::class);
        $sweep = $this->artisan('one-record:outbox:deliver');
        self::assertInstanceOf(PendingCommand::class, $sweep);
        $sweep->assertSuccessful()->run();

        self::assertSame(1, $db->table(self::JOBS)->count(), 'the sweep queued the row once the queue was back');
        DeliverNotification::dispatchFor($this->app(), $id);
        self::assertSame(1, $db->table(self::JOBS)->count(), 'and that job now holds the lock');
    }

    private function recreateSchema(): void
    {
        self::migration(self::CREATE, 'down');
        self::migration(self::CREATE, 'up');
        self::migration(self::LEASE, 'up');
    }

    private static function migration(string $file, string $method): void
    {
        $migration = require $file;
        \assert($migration instanceof Migration);
        $step = [$migration, $method];
        \assert(\is_callable($step));
        $step();
    }

    private function db(): Connection
    {
        $db = $this->app()->make(ConnectionInterface::class);
        self::assertInstanceOf(Connection::class, $db);

        return $db;
    }

    private function outbox(): DatabaseNotificationOutbox
    {
        $outbox = $this->app()->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);

        return $outbox;
    }
}
