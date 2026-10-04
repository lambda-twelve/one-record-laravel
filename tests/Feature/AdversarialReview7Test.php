<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use DateTimeImmutable;
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
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The boundary probes of the seventh adversarial review (2026-10-04, the
 * recheck of the sixth's fixes), kept as regression tests: what else a
 * re-save under the same IRI must carry along in the database store. The
 * SDK's envelope (beta6) says the server never re-saves a request with
 * another payload; the store keeps every query column in step with the
 * document regardless, and these tests hold it to the corners of that.
 */
#[CoversClass(DatabaseActionRequestStore::class)]
#[CoversClass(DatabaseSubscriptionStore::class)]
final class AdversarialReview7Test extends TestCase
{
    use RefreshDatabase;

    private const string OBJECT = self::BASE . '/one-record/logistics-objects/a';

    protected function storageDriver(): string
    {
        return 'database';
    }

    public function testAReplacementChangesTheTopicKindAndClearsTheSubscriptionColumns(): void
    {
        $app = $this->app();
        $store = $app->make(ActionRequestStore::class);
        $subscriptions = $app->make(SubscriptionStore::class);
        $rows = $app->make(ConnectionInterface::class)->table($app->make(Tables::class)->actionRequests());
        $projection = $app->make(ConnectionInterface::class)->table($app->make(Tables::class)->actionRequestObjects());
        $now = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $iri = new Iri(self::BASE . '/one-record/action-requests/topic-kind');
        $object = new Iri(self::OBJECT);
        $partner = new Iri(self::PARTNER);
        $stranger = new Iri(self::STRANGER);

        $store->save(ActionRequest::create($iri, new Subscription($partner, TopicType::Identifier, $object->value, [SubscriptionEventType::LogisticsObjectUpdated]), $partner, $now)->withStatus(RequestStatus::Accepted, $now));
        $created = (clone $rows)->where('iri_hash', IriHash::of($iri))->value('created_at');

        // An identifier subscription becomes a type subscription by another subscriber.
        $later = $now->modify('+1 hour');
        $store->save(ActionRequest::create($iri, new Subscription($stranger, TopicType::Type, Cargo::Piece, [SubscriptionEventType::LogisticsObjectUpdated]), $stranger, $later)->withStatus(RequestStatus::Accepted, $later));
        self::assertSame([], $subscriptions->subscribersOf($object, [], $later), 'no longer an identifier subscription');
        $matches = $subscriptions->subscribersOf($object, [Cargo::Piece], $later);
        self::assertCount(1, $matches);
        self::assertSame(self::STRANGER, $matches[0]['subscription']->subscriber->value);
        self::assertSame(0, (clone $projection)->where('action_request_hash', IriHash::of($iri))->count(), 'a type subscription concerns no single object');

        // Then an access delegation: the subscription columns are cleared, the requester follows, created_at stays.
        $store->save(ActionRequest::create($iri, new AccessDelegation([Permission::GetLogisticsObject], [$partner], [$object]), $stranger, $later)->withStatus(RequestStatus::Accepted, $later));
        self::assertSame([], $subscriptions->subscribersOf($object, [Cargo::Piece], $later));
        $row = (clone $rows)->where('iri_hash', IriHash::of($iri))->first();
        self::assertNotNull($row);
        foreach (['topic', 'topic_type', 'topic_hash', 'subscriber_hash'] as $column) {
            self::assertNull($row->{$column}, $column . ' is cleared');
        }
        self::assertSame(self::STRANGER, $row->requested_by);
        self::assertSame(IriHash::of($stranger), $row->requested_by_hash);
        self::assertSame($created, $row->created_at, 'created_at is the row\'s own');
        self::assertSame(1, (clone $projection)->where('action_request_hash', IriHash::of($iri))->count());
    }

    public function testAReplacementWithALaterRequestTimeMovesInTheAcceptedOrder(): void
    {
        $store = $this->app()->make(ActionRequestStore::class);
        $partner = new Iri(self::PARTNER);
        $payload = new Subscription($partner, TopicType::Identifier, self::OBJECT, [SubscriptionEventType::LogisticsObjectUpdated]);
        $a = new Iri(self::BASE . '/one-record/action-requests/a');
        $b = new Iri(self::BASE . '/one-record/action-requests/b');
        foreach ([[$a, '12:00'], [$b, '13:00'], [$a, '14:00']] as [$iri, $time]) {
            $at = new DateTimeImmutable('2026-10-04T' . $time . ':00Z');
            $store->save(ActionRequest::create($iri, $payload, $partner, $at)->withStatus(RequestStatus::Accepted, $at));
        }

        self::assertSame([$b->value, $a->value], array_map(static fn(ActionRequest $request): string => $request->iri->value, $store->accepted(ActionRequestType::Subscription)));
    }
}
