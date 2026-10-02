<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\AuditTrailQuery;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;
use LambdaTwelve\OneRecord\Spec\ApiVersion;

/**
 * Action requests as the JSON-LD the SDK writes, always at the newest API
 * version (older versions omit the status history and expiry), plus the
 * columns the audit trail, pending-change and subscription queries filter
 * on, and a pivot of the logistics objects each request concerns (an access
 * delegation may cover several).
 */
final class DatabaseActionRequestStore implements ActionRequestStore
{
    use Transactions;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
    ) {}

    public function save(ActionRequest $request): void
    {
        $this->transaction(function () use ($request): void {
            $hash = IriHash::of($request->iri);
            $subscription = $request->payload instanceof Subscription ? $request->payload : null;
            $expires = match (true) {
                $subscription !== null => $subscription->expiresAt,
                $request->payload instanceof AccessDelegation => $request->payload->expiresAt,
                default => null,
            };
            $now = Timestamps::toDb($request->lastModified());
            $version = ApiVersion::latest();

            $this->db->table($this->tables->actionRequests())->upsert([[
                'iri_hash' => $hash,
                'iri' => $request->iri->value,
                'type' => $request->type->name,
                'status' => $request->status->shortName(),
                'requested_by_hash' => IriHash::of($request->requestedBy),
                'requested_by' => $request->requestedBy->value,
                'requested_at' => Timestamps::toDb($request->requestedAt),
                'status_since' => Timestamps::toDb($request->statusSince),
                'last_modified' => $now,
                'topic_type' => $subscription?->topicType->shortName(),
                'topic' => $subscription?->topic,
                'topic_hash' => $subscription === null ? null : IriHash::of($subscription->topic),
                'subscriber_hash' => $subscription === null ? null : IriHash::of($subscription->subscriber),
                'expires_at' => Timestamps::toDb($expires),
                'api_version' => $version->value,
                'document' => Json::encode($request->toJsonLd($version), false),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['iri_hash'], ['status', 'status_since', 'last_modified', 'expires_at', 'api_version', 'document', 'updated_at']);

            $objects = [];
            foreach ($request->logisticsObjects() as $object) {
                $objects[] = ['action_request_hash' => $hash, 'logistics_object_hash' => IriHash::of($object), 'logistics_object_iri' => $object->value];
            }
            if ($objects !== []) {
                // The payload is immutable, so the set of objects never changes after the first save.
                $this->db->table($this->tables->actionRequestObjects())->insertOrIgnore($objects);
            }
        });
    }

    public function get(Iri $iri): ?ActionRequest
    {
        $row = Row::first($this->db->table($this->tables->actionRequests())->where('iri_hash', IriHash::of($iri)));

        return $row === null ? null : self::hydrate($row);
    }

    public function auditTrail(Iri $logisticsObject, AuditTrailQuery $query): array
    {
        $q = $this->about($logisticsObject)->whereIn('ar.type', [ActionRequestType::Change->name, ActionRequestType::Verification->name]);
        if ($query->updatedFrom !== null) {
            $q->where('ar.last_modified', '>=', Timestamps::toDb($query->updatedFrom));
        }
        if ($query->updatedTo !== null) {
            $q->where('ar.last_modified', '<=', Timestamps::toDb($query->updatedTo));
        }
        if ($query->status !== null) {
            $q->where('ar.status', $query->status->shortName());
        }

        return array_map(self::hydrate(...), Row::all($q));
    }

    public function accepted(ActionRequestType $type): array
    {
        $q = $this->db->table($this->tables->actionRequests())
            ->where('type', $type->name)
            ->where('status', RequestStatus::Accepted->shortName())
            ->orderBy('requested_at');

        return array_map(self::hydrate(...), Row::all($q));
    }

    public function transition(ActionRequest $request, RequestStatus $expectedCurrent): void
    {
        $this->transaction(function () use ($request, $expectedCurrent): void {
            $hash = IriHash::of($request->iri);
            $expires = match (true) {
                $request->payload instanceof Subscription => $request->payload->expiresAt,
                $request->payload instanceof AccessDelegation => $request->payload->expiresAt,
                default => null,
            };
            $now = Timestamps::toDb($request->lastModified());
            $version = ApiVersion::latest();
            // Compare-and-set on the stored status: a decision made on a stale snapshot must not win.
            $updated = $this->db->table($this->tables->actionRequests())
                ->where('iri_hash', $hash)
                ->where('status', $expectedCurrent->shortName())
                ->update([
                    'status' => $request->status->shortName(),
                    'status_since' => Timestamps::toDb($request->statusSince),
                    'last_modified' => $now,
                    'expires_at' => Timestamps::toDb($expires),
                    'api_version' => $version->value,
                    'document' => Json::encode($request->toJsonLd($version), false),
                    'updated_at' => $now,
                ]);
            if ($updated === 0) {
                $current = $this->get($request->iri) ?? throw StoreException::notFound($request->iri);
                throw StoreException::statusConflict($request->iri, $expectedCurrent->shortName(), $current->status->shortName());
            }
        });
    }

    public function pendingChanges(Iri $logisticsObject): array
    {
        $q = $this->about($logisticsObject)
            ->where('ar.type', ActionRequestType::Change->name)
            ->where('ar.status', RequestStatus::Pending->shortName());

        return array_map(self::hydrate(...), Row::all($q));
    }

    private function about(Iri $logisticsObject): Builder
    {
        return $this->db->table($this->tables->actionRequests() . ' as ar')
            ->join($this->tables->actionRequestObjects() . ' as o', 'o.action_request_hash', '=', 'ar.iri_hash')
            ->where('o.logistics_object_hash', IriHash::of($logisticsObject))
            ->orderBy('ar.requested_at')
            ->orderBy('ar.iri')
            ->select(['ar.document']);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): ActionRequest
    {
        return ActionRequest::fromJsonLd(Row::string($row, 'document'));
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db;
    }
}
