<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ActionRequests;
use LambdaTwelve\OneRecord\Server\Event\ActionRequestStatusChanged;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The probes of the sixth adversarial review (2026-10-04, the beta2
 * readiness pass against 72dcc18), kept as regression tests. Two are about
 * save() replacing a request whole in the database store; one pins how the
 * SDK's decision events (after their effects, since beta4) meet the unit of
 * work bound here.
 */
#[CoversClass(DatabaseActionRequestStore::class)]
#[CoversClass(DatabaseSubscriptionStore::class)]
final class AdversarialReview6Test extends TestCase
{
    use RefreshDatabase;

    private const string A = self::BASE . '/one-record/logistics-objects/a';
    private const string B = self::BASE . '/one-record/logistics-objects/b';

    protected function storageDriver(): string
    {
        return 'database';
    }

    /**
     * AR6-001. An access delegation built in PHP may name the same object
     * twice; the projection used to insert both rows and fail on its unique
     * key, rolling the whole save back.
     */
    public function testADelegationNamingTheSameObjectTwiceIsSaved(): void
    {
        $store = $this->requests();
        $object = new Iri(self::A);
        $request = ActionRequest::create(new Iri(self::BASE . '/one-record/action-requests/duplicate'), new AccessDelegation([Permission::GetLogisticsObject], [new Iri(self::PARTNER)], [$object, $object]), new Iri(self::PARTNER), new DateTimeImmutable('2026-10-04T12:00:00Z'));

        $store->save($request);

        self::assertNotNull($store->get($request->iri));
        $projection = $this->app()->make(ConnectionInterface::class)->table($this->app()->make(Tables::class)->actionRequestObjects());
        self::assertSame(1, $projection->where('logistics_object_hash', IriHash::of($object))->count(), 'one projection row per distinct object');
    }

    /**
     * AR6-002, subscriptions. A subscription request replaced under the same
     * IRI for another object was found by get() but not by subscribersOf():
     * the topic columns the SQL filters on still named the old object.
     */
    public function testAReplacedSubscriptionIsFoundForTheObjectItNowConcerns(): void
    {
        $store = $this->requests();
        $subscriptions = $this->app()->make(SubscriptionStore::class);
        $now = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $iri = new Iri(self::BASE . '/one-record/action-requests/move');
        foreach ([self::A, self::B] as $object) {
            $store->save(ActionRequest::create($iri, new Subscription(new Iri(self::PARTNER), TopicType::Identifier, $object, [SubscriptionEventType::LogisticsObjectUpdated]), new Iri(self::PARTNER), $now)->withStatus(RequestStatus::Accepted, $now));
        }

        $payload = $store->get($iri)?->payload;
        self::assertInstanceOf(Subscription::class, $payload);
        self::assertSame(self::B, $payload->topic);
        self::assertCount(0, $subscriptions->subscribersOf(new Iri(self::A), [], $now));
        self::assertCount(1, $subscriptions->subscribersOf(new Iri(self::B), [], $now), 'the replacement subscriber receives notifications for B');
    }

    /**
     * AR6-002, types. A request replaced under the same IRI with a payload of
     * another type kept its old type column, so accepted() filed it wrongly.
     */
    public function testAReplacedRequestIsFiledUnderItsNewType(): void
    {
        $store = $this->requests();
        $now = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $iri = new Iri(self::BASE . '/one-record/action-requests/type');
        $partner = new Iri(self::PARTNER);
        $store->save(ActionRequest::create($iri, new AccessDelegation([Permission::GetLogisticsObject], [$partner], [new Iri(self::A)]), $partner, $now)->withStatus(RequestStatus::Accepted, $now));
        $store->save(ActionRequest::create($iri, new Subscription($partner, TopicType::Identifier, self::A, [SubscriptionEventType::LogisticsObjectUpdated]), $partner, $now)->withStatus(RequestStatus::Accepted, $now));

        self::assertSame(ActionRequestType::Subscription, $store->get($iri)?->type);
        self::assertCount(1, $store->accepted(ActionRequestType::Subscription));
        self::assertSame([], $store->accepted(ActionRequestType::AccessDelegation));
    }

    /**
     * Review 6's passing probe, kept as the pin: since SDK beta4 a decision's
     * event fires after its effects, so a listener sees the grant made or
     * removed, and a listener that throws rolls the decision back with them.
     */
    public function testDecisionListenersSeeTheEffectsAndAThrowingOneRollsThemBack(): void
    {
        $app = $this->app();
        $actions = $app->make(ActionRequests::class);
        $grants = $app->make(AccessDelegationStore::class);
        $partner = new Iri(self::PARTNER);
        $object = new Iri(self::A);
        $request = $actions->create(new AccessDelegation([Permission::GetLogisticsObject], [$partner], [$object]), $partner);
        $listener = new class {
            public bool $fail = false;

            /** @var list<array{RequestStatus, int}> */
            public array $observed = [];

            /**
             * Read through a method so the static analyser does not pin the array to its first contents.
             *
             * @return list<array{RequestStatus, int}>
             */
            public function seen(): array
            {
                return $this->observed;
            }
        };
        $app->make(Dispatcher::class)->listen(ActionRequestStatusChanged::class, static function (ActionRequestStatusChanged $event) use ($grants, $partner, $object, $listener): void {
            $listener->observed[] = [$event->request->status, \count($grants->grantsFor($partner, $object))];
            if ($listener->fail) {
                throw new RuntimeException('listener rejected the decision');
            }
        });

        $accepted = $actions->accept($request, new Iri(self::HOLDER));
        self::assertSame([[RequestStatus::Accepted, 1]], $listener->seen(), 'the listener sees the grant the acceptance made');

        $listener->fail = true;
        try {
            $actions->revoke($accepted, new Iri(self::HOLDER));
            self::fail('the listener failure propagates');
        } catch (RuntimeException $e) {
            self::assertSame('listener rejected the decision', $e->getMessage());
        }
        self::assertSame([[RequestStatus::Accepted, 1], [RequestStatus::Revoked, 0]], $listener->seen(), 'the listener saw the grant gone before it threw');
        self::assertSame(RequestStatus::Accepted, $this->requests()->get($request->iri)?->status, 'and the revocation rolled back');
        self::assertCount(1, $grants->grantsFor($partner, $object));
    }

    private function requests(): ActionRequestStore
    {
        return $this->app()->make(ActionRequestStore::class);
    }
}
