<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;

/**
 * Publisher side: an accepted, unexpired subscription request is a
 * subscription, so subscribers are derived from the action-request table
 * (the SQL narrows by topic, status and expiry; the SDK's own covers() and
 * isExpiredAt() make the final call). Subscriber side: the subscriptions
 * this host offers when a partner asks GET /subscriptions, kept in their
 * own table through offer() and withdrawOffers(), which the SDK's SPI does
 * not define.
 */
final class DatabaseSubscriptionStore implements SubscriptionStore
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
    ) {}

    public function subscribersOf(Iri $logisticsObject, array $types, DateTimeImmutable $now): array
    {
        $q = $this->db->table($this->tables->actionRequests())
            ->where('type', ActionRequestType::Subscription->name)
            ->where('status', RequestStatus::Accepted->shortName())
            ->where(static function (Builder $where) use ($now): void {
                $where->whereNull('expires_at')->orWhere('expires_at', '>', Timestamps::toDb($now));
            })
            ->where(static function (Builder $where) use ($logisticsObject, $types): void {
                $where->where(static function (Builder $identifier) use ($logisticsObject): void {
                    $identifier->where('topic_type', TopicType::Identifier->shortName())->where('topic_hash', IriHash::of($logisticsObject));
                });
                if ($types !== []) {
                    $where->orWhere(static function (Builder $type) use ($types): void {
                        $type->where('topic_type', TopicType::Type->shortName())->whereIn('topic_hash', array_map(IriHash::of(...), $types));
                    });
                }
            })
            ->orderBy('id')
            ->select(['document']);

        $out = [];
        foreach (Row::all($q) as $row) {
            $request = DatabaseActionRequestStore::hydrate($row);
            $subscription = $request->payload;
            if ($subscription instanceof Subscription && !$subscription->isExpiredAt($now) && $subscription->covers($logisticsObject, $types)) {
                $out[] = ['subscription' => $subscription, 'request' => $request->iri];
            }
        }

        return $out;
    }

    public function offered(TopicType $topicType, string $topic): array
    {
        $rows = Row::all($this->db->table($this->tables->subscriptionOffers())
            ->where('topic_type', $topicType->shortName())
            ->where('topic_hash', IriHash::of($topic))
            ->orderBy('id')
            ->select(['document']));

        return array_map(static fn(array $row): Subscription => Subscription::fromJsonLd(Row::string($row, 'document')), $rows);
    }

    /**
     * Registers a subscription this host wants partners to offer it (answered
     * on GET /subscriptions). Mirrors InMemorySubscriptionStore::offer().
     */
    public function offer(Subscription $subscription, ?DateTimeImmutable $at = null): void
    {
        $this->db->table($this->tables->subscriptionOffers())->insert([
            'topic_type' => $subscription->topicType->shortName(),
            'topic' => $subscription->topic,
            'topic_hash' => IriHash::of($subscription->topic),
            'document' => Json::encode($subscription->toJsonLd(), false),
            'created_at' => Timestamps::toDb($at ?? new DateTimeImmutable('now')),
        ]);
    }

    public function withdrawOffers(TopicType $topicType, string $topic): void
    {
        $this->db->table($this->tables->subscriptionOffers())
            ->where('topic_type', $topicType->shortName())
            ->where('topic_hash', IriHash::of($topic))
            ->delete();
    }
}
