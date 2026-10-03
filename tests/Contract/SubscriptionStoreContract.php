<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

/**
 * Beyond the SDK's shipped SubscriptionStoreContract (which runs against the
 * database store in tests/Contract/Sdk): subscribers come back in request
 * order, an empty type list keeps only identifier subscriptions, and a
 * subscription expires at exactly its expiry instant. Run against the SDK's
 * in-memory stores (the reference) and the database stores.
 */
trait SubscriptionStoreContract
{
    abstract protected function requests(): ActionRequestStore;

    abstract protected function subscriptions(): SubscriptionStore;

    private function subscribe(string $id, string $subscriber, TopicType $topicType, string $topic, RequestStatus $status, ?string $expires = null): Iri
    {
        $iri = new Iri(Documents::BASE . '/action-requests/' . $id);
        $subscription = new Subscription(new Iri($subscriber), $topicType, $topic, [SubscriptionEventType::LogisticsObjectUpdated], expiresAt: $expires === null ? null : Documents::at($expires));
        $request = ActionRequest::create($iri, $subscription, new Iri($subscriber), Documents::at('2026-10-02T09:00:00.000Z'));
        if ($status !== RequestStatus::Pending) {
            $request = $request->withStatus($status, Documents::at('2026-10-02T09:30:00.000Z'));
        }
        $this->requests()->save($request);

        return $iri;
    }

    public function testOnlyAcceptedUnexpiredSubscriptionsCoveringTheObjectCount(): void
    {
        $now = Documents::at('2026-10-02T12:00:00.000Z');
        $byId = $this->subscribe('s-id', Documents::PARTNER, TopicType::Identifier, Documents::iri('p1')->value, RequestStatus::Accepted);
        $byType = $this->subscribe('s-type', Documents::OTHER, TopicType::Type, Cargo::Piece, RequestStatus::Accepted, '2026-10-02T12:00:00.001Z');
        $this->subscribe('s-pending', Documents::PARTNER, TopicType::Identifier, Documents::iri('p1')->value, RequestStatus::Pending);
        $this->subscribe('s-revoked', Documents::PARTNER, TopicType::Identifier, Documents::iri('p1')->value, RequestStatus::Revoked);
        $this->subscribe('s-expired', Documents::PARTNER, TopicType::Identifier, Documents::iri('p1')->value, RequestStatus::Accepted, '2026-10-02T12:00:00.000Z');
        $this->subscribe('s-other-object', Documents::PARTNER, TopicType::Identifier, Documents::iri('p2')->value, RequestStatus::Accepted);
        $this->subscribe('s-other-type', Documents::PARTNER, TopicType::Type, Cargo::Shipment, RequestStatus::Accepted);

        $subscribers = $this->subscriptions()->subscribersOf(Documents::iri('p1'), [Cargo::Piece], $now);

        self::assertSame([$byId->value, $byType->value], array_map(static fn(array $s): string => $s['request']->value, $subscribers));
        self::assertSame(Documents::PARTNER, $subscribers[0]['subscription']->subscriber->value);
        self::assertSame(Documents::OTHER, $subscribers[1]['subscription']->subscriber->value);

        self::assertSame([$byId->value], array_map(static fn(array $s): string => $s['request']->value, $this->subscriptions()->subscribersOf(Documents::iri('p1'), [], $now)));
        self::assertSame([], $this->subscriptions()->subscribersOf(Documents::iri('p3'), [Cargo::ULD], $now));
        self::assertSame([$byId->value], array_map(static fn(array $s): string => $s['request']->value, $this->subscriptions()->subscribersOf(Documents::iri('p1'), [Cargo::Piece], Documents::at('2026-10-02T12:00:00.001Z'))), 'the type subscription expires at exactly its expiry instant');
    }

}
