<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseClientCredentials;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseUnitOfWork;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\CountingHasher;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\HeaderAuthenticator;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\Event\LogisticsObjectCreated;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Services;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\Action;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\Agent;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use LambdaTwelve\OneRecord\Server\Spi\Decision;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use LambdaTwelve\OneRecord\Testing\RacingActionRequestStore;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * The reproduction probes of the adversarial review of 2026-10-04, kept as
 * regression tests. Each probe demonstrated a failure; each test now pins
 * the behaviour that replaced it. Numbers refer to the review's findings.
 */
#[CoversClass(DatabaseUnitOfWork::class)]
#[CoversClass(DatabaseNotificationOutbox::class)]
#[CoversClass(DatabaseClientCredentials::class)]
#[CoversClass(DeliverNotification::class)]
final class AdversarialReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->config()->set('one-record.outbox.dispatch', 'none');
        $this->app()->instance(Authenticator::class, new HeaderAuthenticator());
    }

    /**
     * Finding 1. Accepting an access delegation used to write its grants
     * before the final status update; when that update lost to a competing
     * decision the SDK answered 409, and without the unit of work the grants
     * stayed committed behind the 409 and the policy allowed the partner in.
     * Two things now stand between that and a partner: the unit of work bound
     * here, and, since SDK beta3, the compare-and-set on the status coming
     * before any side effect, so a lost decision writes nothing even without
     * a transaction.
     *
     * The race is staged with the SDK's RacingActionRequestStore (beta4): the
     * competing worker rejects the request just before our compare-and-set.
     * It runs on the same connection as the request, so when the acceptance
     * unwinds, the injected rejection unwinds with it; a real competitor
     * commits on its own connection and the stored status would read Rejected.
     */
    public function testADecisionThatLosesTheStatusRaceLeavesNoGrantBehind(): void
    {
        $app = $this->app();
        $real = $app->make(ActionRequestStore::class);
        $config = $app->make(ServerConfig::class);
        $object = $config->logisticsObjectIri('race-object');
        $partner = new Iri(self::PARTNER);
        $holder = new Iri(self::HOLDER);
        $request = ActionRequest::create($config->actionRequestIri('race'), new AccessDelegation([Permission::GetLogisticsObject], [$partner], [$object]), $partner, $app->make(ClockInterface::class)->now());
        $real->save($request);

        $racing = new RacingActionRequestStore($real);
        $racing->arm($request->iri, static function (ActionRequest $accepting) use ($real, $holder): void {
            $pending = $real->get($accepting->iri);
            self::assertNotNull($pending);
            $real->transition($pending->withStatus(RequestStatus::Rejected, new DateTimeImmutable(), $holder), RequestStatus::Pending);
        });
        $app->instance(ActionRequestStore::class, $racing);

        $response = $this->call('PATCH', $request->iri->value . '?status=REQUEST_ACCEPTED', [], [], [], [
            'HTTP_ACCEPT' => 'application/ld+json; version=2.3.0',
            'HTTP_X_TEST_AGENT' => self::HOLDER,
        ]);

        $response->assertStatus(409);
        self::assertSame(1, $racing->racesLost);
        self::assertNotSame(RequestStatus::Accepted, $real->get($request->iri)?->status);
        self::assertSame([], $app->make(AccessDelegationStore::class)->grantsFor($partner, $object), 'the grants of the lost acceptance were rolled back');
        self::assertSame(Decision::Forbid, $app->make(AccessPolicy::class)->decide(new Agent($partner), Action::ReadLogisticsObject, $object));
    }

    /**
     * Finding 1, the PHP side. A DataHolder operation is atomic by itself; a
     * listener that throws leaves no object behind, with no DB::transaction()
     * around the call.
     */
    public function testAFailingListenerLeavesNoObjectBehindADirectOperation(): void
    {
        $app = $this->app();
        $iri = $app->make(ServerConfig::class)->logisticsObjectIri('partial');
        $app->make(Dispatcher::class)->listen(LogisticsObjectCreated::class, static function (): void {
            throw new RuntimeException('listener failed');
        });

        try {
            $app->make(DataHolder::class)->create(ObjectBuilder::of(Cargo::Piece)->build($iri));
            self::fail('the listener failure propagates');
        } catch (RuntimeException $e) {
            self::assertSame('listener failed', $e->getMessage());
        }
        self::assertFalse($app->make(Services::class)->objects->exists($iri), 'the unit of work rolled the create back');
    }

    /**
     * Finding 2. Worker A's lease expires; B claims the row; A reports a
     * permanent failure; B schedules a retry. A's late outcome used to stick
     * (failed_at set), and the row was never delivered again.
     */
    public function testAnExpiredLeaseCannotRecordAnOutcomeOverTheCurrentOne(): void
    {
        $app = $this->app();
        $outbox = $app->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
        $now = $app->make(ClockInterface::class)->now();
        $outbox->enqueue(new OutboundNotification(new Iri(self::PARTNER), new Notification(NotificationEventType::LogisticsObjectCreated), $now, 'lease-race'));
        [$id] = $outbox->due($now, 1);

        $workerA = $outbox->claim($id, $now, 120);
        $workerB = $outbox->claim($id, $now->modify('+121 seconds'), 120);
        self::assertNotNull($workerA);
        self::assertNotNull($workerB, 'the expired lease is up for grabs');
        self::assertSame(2, $workerB->pending->attempts, 'the claim hands back the attempt it counted');

        self::assertFalse($outbox->markFailed($workerA, $now->modify('+122 seconds'), 'late rejection from worker A'), 'an expired lease records nothing');
        self::assertTrue($outbox->markRetry($workerB, $now->modify('+300 seconds'), 'transient failure from worker B'));

        self::assertSame([], $outbox->due($now->modify('+299 seconds'), 10));
        self::assertSame([$id], $outbox->due($now->modify('+1 day'), 10), 'the row is delivered again when B\'s retry is due');
    }

    /**
     * Finding 4. Verifying an unknown client id used to make a throwaway hash
     * and then check it, one hash operation more than a known id; now both
     * paths do the same work, cold cache or warm.
     */
    public function testVerifyingAnUnknownClientCostsTheSameAsAKnownOne(): void
    {
        $app = $this->app();
        $hasher = new CountingHasher();
        $credentials = static fn(Cache $cache): DatabaseClientCredentials => new DatabaseClientCredentials($app->make(ConnectionInterface::class), $app->make(Tables::class), $hasher, $app->make(ClockInterface::class), $cache);
        $cache = new CacheRepository(new ArrayStore());
        $credentials($cache)->create('known', 'secret', new Iri(self::PARTNER));

        $cost = static function (DatabaseClientCredentials $verifier, string $id) use ($hasher): array {
            $hasher->reset();
            $verifier->verify($id, 'wrong');

            return ['makes' => $hasher->makes, 'checks' => $hasher->checks];
        };

        self::assertSame(['makes' => 1, 'checks' => 1], $cost($credentials(new CacheRepository(new ArrayStore())), 'unknown'), 'cold: the throwaway hash is made, then one check');
        self::assertSame(['makes' => 1, 'checks' => 1], $cost($credentials(new CacheRepository(new ArrayStore())), 'known'), 'cold, known id: the same work');
        $credentials($cache)->verify('warm-up', 'x');
        self::assertSame(['makes' => 0, 'checks' => 1], $cost($credentials($cache), 'unknown'), 'warm: one check');
        self::assertSame(['makes' => 0, 'checks' => 1], $cost($credentials($cache), 'known'), 'warm, known id: one check');
        self::assertSame(self::PARTNER, $credentials($cache)->verify('known', 'secret')?->value);
    }

    /**
     * Finding 5. The job declared itself unique, but the bus contract's
     * dispatch() never takes the unique lock, so repeated sweeps queued the
     * same row again and again. Every dispatch now goes through dispatchFor().
     */
    public function testDispatchingTheSameRowTwiceQueuesOneJob(): void
    {
        Bus::fake();

        DeliverNotification::dispatchFor($this->app(), 42);
        DeliverNotification::dispatchFor($this->app(), 42);
        Bus::assertDispatchedTimes(DeliverNotification::class, 1);

        // The lock is what a worker releases when it starts the job; afterwards the row may be queued again.
        (new UniqueLock($this->app()->make(Cache::class)))->release(new DeliverNotification(42));
        DeliverNotification::dispatchFor($this->app(), 42);
        Bus::assertDispatchedTimes(DeliverNotification::class, 2);
    }
}
