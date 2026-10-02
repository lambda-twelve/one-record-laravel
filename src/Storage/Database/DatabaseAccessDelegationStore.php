<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\Grant;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Api;
use UnexpectedValueException;

/**
 * Grants: the holder's own authorisations (no source) and those created by
 * accepted access-delegation requests (source = the request IRI, which is
 * what revokeFrom() removes by).
 */
final class DatabaseAccessDelegationStore implements AccessDelegationStore
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
    ) {}

    public function grant(Grant $grant): void
    {
        $this->db->table($this->tables->grants())->insert([
            'agent_hash' => IriHash::of($grant->agent),
            'agent' => $grant->agent->value,
            'logistics_object_hash' => IriHash::of($grant->logisticsObject),
            'logistics_object_iri' => $grant->logisticsObject->value,
            'permissions' => implode(',', array_map(static fn(Permission $p): string => substr($p->value, \strlen(Api::NAMESPACE)), $grant->permissions)),
            'expires_at' => Timestamps::toDb($grant->expiresAt),
            'source_hash' => $grant->source === null ? null : IriHash::of($grant->source),
            'source' => $grant->source?->value,
            'created_at' => Timestamps::toDb(new DateTimeImmutable('now')),
        ]);
    }

    public function grantsFor(Iri $agent, Iri $logisticsObject): array
    {
        $rows = Row::all($this->db->table($this->tables->grants())
            ->where('agent_hash', IriHash::of($agent))
            ->where('logistics_object_hash', IriHash::of($logisticsObject))
            ->orderBy('id'));

        return array_map(static function (array $row): Grant {
            $permissions = [];
            foreach (explode(',', Row::string($row, 'permissions')) as $name) {
                $permissions[] = Permission::tryFromString($name) ?? throw new UnexpectedValueException(\sprintf('Unknown permission "%s" in a stored grant.', $name));
            }
            $source = Row::nullableString($row, 'source');

            return new Grant(
                new Iri(Row::string($row, 'agent')),
                new Iri(Row::string($row, 'logistics_object_iri')),
                $permissions,
                Timestamps::fromDbNullable($row['expires_at'] ?? null),
                $source === null ? null : new Iri($source),
            );
        }, $rows);
    }

    public function revokeFrom(Iri $accessDelegationRequest): void
    {
        $this->db->table($this->tables->grants())->where('source_hash', IriHash::of($accessDelegationRequest))->delete();
    }

    /**
     * Removes the holder's own grants for one agent on one object (the
     * counterpart of GrantAccessPolicy::allow()). Not part of the SPI.
     */
    public function revokeDirect(Iri $agent, Iri $logisticsObject): void
    {
        $this->db->table($this->tables->grants())
            ->where('agent_hash', IriHash::of($agent))
            ->where('logistics_object_hash', IriHash::of($logisticsObject))
            ->whereNull('source_hash')
            ->delete();
    }

    /**
     * Closes every access to an object; the first step of the forget flow
     * described in the SDK's guide. Not part of the SPI.
     */
    public function eraseFor(Iri $logisticsObject): void
    {
        $this->db->table($this->tables->grants())->where('logistics_object_hash', IriHash::of($logisticsObject))->delete();
    }
}
