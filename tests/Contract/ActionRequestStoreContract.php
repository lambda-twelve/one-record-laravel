<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\Error;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Api\Verification;
use LambdaTwelve\OneRecord\Change\Change;
use LambdaTwelve\OneRecord\Change\ChangeBuilder;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\AuditTrailQuery;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

/**
 * Host behaviour the SDK's shipped ActionRequestStoreContract leaves open:
 * the whole status history and every payload's details after a round trip,
 * inclusive audit-trail bounds on the last modification, and the IRI as the
 * tie-break on equal timestamps. Run against the SDK's in-memory store (the
 * reference) and the database store; the SDK's own contract runs against the
 * database store in tests/Contract/Sdk.
 */
trait ActionRequestStoreContract
{
    abstract protected function requests(): ActionRequestStore;

    private function requestIri(string $id): Iri
    {
        return new Iri(Documents::BASE . '/action-requests/' . $id);
    }

    private function change(string $objectId, string $to = 'Changed'): Change
    {
        $change = (new ChangeBuilder())->diff(Documents::piece($objectId, 'Original'), Documents::piece($objectId, $to), 1, 'Fix the description');
        self::assertNotNull($change);

        return $change;
    }

    public function testEveryPayloadTypeRoundTripsWithItsStatusHistory(): void
    {
        $t0 = Documents::at('2026-10-02T10:00:00.000Z');
        $t1 = Documents::at('2026-10-02T10:05:00.000Z');
        $expires = Documents::at('2026-12-31T23:59:59.000Z');
        $delegate = new Iri('https://forwarder.example/logistics-objects/forwarder');

        $change = ActionRequest::create($this->requestIri('c1'), $this->change('p1'), new Iri(Documents::PARTNER), $t0)
            ->withStatus(RequestStatus::Rejected, $t1, new Iri(Documents::iri('holder')->value), [Error::of('Not acceptable', 'TEST', 'Rejected by the holder')]);
        $verification = ActionRequest::create($this->requestIri('v1'), new Verification(Documents::iri('p1'), [Error::of('Wrong weight')], 1), new Iri(Documents::PARTNER), $t0);
        $subscription = ActionRequest::create($this->requestIri('s1'), new Subscription(new Iri(Documents::PARTNER), TopicType::Type, Cargo::Piece, [SubscriptionEventType::LogisticsObjectUpdated], sendLogisticsObjectBody: true, expiresAt: $expires), new Iri(Documents::PARTNER), $t0)
            ->withStatus(RequestStatus::Accepted, $t1);
        $delegation = ActionRequest::create($this->requestIri('d1'), new AccessDelegation([Permission::GetLogisticsObject, Permission::GetLogisticsEvent], [$delegate], [Documents::iri('p1'), Documents::iri('p2')], 'Handling partner', expiresAt: $expires), new Iri(Documents::PARTNER), $t0)
            ->withStatus(RequestStatus::Accepted, $t1)->withStatus(RequestStatus::Revoked, $t1->modify('+1 hour'), new Iri(Documents::PARTNER));

        foreach ([$change, $verification, $subscription, $delegation] as $request) {
            $this->requests()->save($request);
        }

        $read = $this->requests()->get($this->requestIri('c1'));
        self::assertNotNull($read);
        self::assertSame(ActionRequestType::Change, $read->type);
        self::assertSame(RequestStatus::Rejected, $read->status);
        self::assertEquals($t0, $read->requestedAt);
        self::assertEquals($t1, $read->statusSince);
        self::assertSame(Documents::PARTNER, $read->requestedBy->value);
        self::assertCount(1, $read->history);
        self::assertSame(RequestStatus::Pending, $read->history[0]->status);
        self::assertCount(1, $read->errors);
        self::assertSame('Not acceptable', $read->errors[0]->title);
        self::assertInstanceOf(Change::class, $read->payload);
        self::assertSame(1, $read->payload->revision);
        self::assertSame('Fix the description', $read->payload->description);
        self::assertSame([Documents::iri('p1')->value], array_map(static fn(Iri $i): string => $i->value, $read->logisticsObjects()));

        $read = $this->requests()->get($this->requestIri('v1'));
        self::assertInstanceOf(Verification::class, $read?->payload);
        self::assertSame(1, $read->payload->revision);
        self::assertSame('Wrong weight', $read->payload->errors[0]->title);

        $read = $this->requests()->get($this->requestIri('s1'));
        self::assertInstanceOf(Subscription::class, $read?->payload);
        self::assertSame(RequestStatus::Accepted, $read->status);
        self::assertSame(TopicType::Type, $read->payload->topicType);
        self::assertSame(Cargo::Piece, $read->payload->topic);
        self::assertTrue($read->payload->sendLogisticsObjectBody);
        self::assertEquals($expires, $read->payload->expiresAt);

        $read = $this->requests()->get($this->requestIri('d1'));
        self::assertInstanceOf(AccessDelegation::class, $read?->payload);
        self::assertSame(RequestStatus::Revoked, $read->status);
        self::assertCount(2, $read->history);
        self::assertSame(Documents::PARTNER, $read->revokedBy?->value);
        self::assertEquals($t1->modify('+1 hour'), $read->revokedAt);
        self::assertSame([$delegate->value], array_map(static fn(Iri $i): string => $i->value, $read->payload->delegates));
        self::assertCount(2, $read->payload->logisticsObjects);
        self::assertEquals($expires, $read->payload->expiresAt);
        self::assertEqualsCanonicalizing([Permission::GetLogisticsObject, Permission::GetLogisticsEvent], $read->payload->permissions);

        self::assertNull($this->requests()->get($this->requestIri('missing')));
    }


    private function seedTrail(): void
    {
        $partner = new Iri(Documents::PARTNER);
        $this->requests()->save(ActionRequest::create($this->requestIri('c1'), $this->change('p1', 'first'), $partner, Documents::at('2026-10-02T10:00:00.000Z')));
        $this->requests()->save(ActionRequest::create($this->requestIri('v1'), new Verification(Documents::iri('p1'), [Error::of('Wrong')], 1), $partner, Documents::at('2026-10-02T10:05:00.000Z')));
        $this->requests()->save(ActionRequest::create($this->requestIri('c2'), $this->change('p1', 'second'), $partner, Documents::at('2026-10-02T10:10:00.000Z'))
            ->withStatus(RequestStatus::Rejected, Documents::at('2026-10-02T10:20:00.000Z')));
        // Subscriptions never appear in an audit trail; other objects' requests neither.
        $this->requests()->save(ActionRequest::create($this->requestIri('s1'), new Subscription($partner, TopicType::Identifier, Documents::iri('p1')->value, [SubscriptionEventType::LogisticsObjectUpdated]), $partner, Documents::at('2026-10-02T10:02:00.000Z')));
        $this->requests()->save(ActionRequest::create($this->requestIri('c9'), $this->change('p2'), $partner, Documents::at('2026-10-02T10:00:00.000Z')));
    }

    /**
     * @return list<string>
     */
    private function trail(AuditTrailQuery $query): array
    {
        return array_map(static fn(ActionRequest $r): string => basename($r->iri->value), $this->requests()->auditTrail(Documents::iri('p1'), $query));
    }


    public function testAuditTrailBoundsAreInclusiveOnTheLastModification(): void
    {
        $this->seedTrail();

        self::assertSame(['c2'], $this->trail(new AuditTrailQuery(updatedFrom: Documents::at('2026-10-02T10:20:00.000Z'))));
        self::assertSame(['v1', 'c2'], $this->trail(new AuditTrailQuery(updatedFrom: Documents::at('2026-10-02T10:05:00.000Z'))));
        self::assertSame(['c1', 'v1'], $this->trail(new AuditTrailQuery(updatedTo: Documents::at('2026-10-02T10:05:00.000Z'))));
        self::assertSame(['v1'], $this->trail(new AuditTrailQuery(updatedFrom: Documents::at('2026-10-02T10:01:00.000Z'), updatedTo: Documents::at('2026-10-02T10:19:59.999Z'))));
        self::assertSame(['c1', 'v1'], $this->trail(new AuditTrailQuery(status: RequestStatus::Pending)));
        self::assertSame(['c2'], $this->trail(new AuditTrailQuery(status: RequestStatus::Rejected)));
    }


    public function testRequestsWithTheSameTimestampOrderByIri(): void
    {
        $partner = new Iri(Documents::PARTNER);
        $at = Documents::at('2026-10-02T10:00:00.000Z');
        $this->requests()->save(ActionRequest::create($this->requestIri('b'), $this->change('p1'), $partner, $at));
        $this->requests()->save(ActionRequest::create($this->requestIri('a'), $this->change('p1'), $partner, $at));

        self::assertSame(['a', 'b'], $this->trail(AuditTrailQuery::all()));
    }
}
