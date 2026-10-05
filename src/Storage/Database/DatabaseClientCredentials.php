<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LambdaTwelve\OneRecord\Auth\ClientCredentialsVerifier;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Rdf\Iri;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

/**
 * The partners this host issues tokens to, with secrets hashed by Laravel's
 * configured hasher. Like the SDK's in-memory verifier it does the same work
 * whether or not the client id exists: one hash check, against a throwaway
 * hash when the id is unknown. That hash is made with the configured hasher
 * (same algorithm and cost as the real ones) and kept in the package's cache,
 * because a hash made lazily per PHP process would itself be the extra work
 * that tells an unknown id from a known one (R-004).
 */
final class DatabaseClientCredentials implements ClientCredentialsVerifier
{
    private const string DUMMY_HASH_KEY = 'one-record.client-credentials.dummy-hash';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
        private readonly Hasher $hasher,
        private readonly ClockInterface $clock,
        private readonly CacheInterface $cache,
    ) {}

    public function verify(string $clientId, string $clientSecret): ?Iri
    {
        $dummy = $this->dummyHash();   // before the lookup, on every call: the same cost on both paths
        $row = Row::first($this->db->table($this->tables->clients())->where('client_id', $clientId));
        $valid = $this->hasher->check($clientSecret, $row === null ? $dummy : Row::string($row, 'secret_hash'));
        if ($row === null || !$valid || !self::enabled($row)) {
            return null;
        }
        $this->db->table($this->tables->clients())->where('client_id', $clientId)->update(['last_used_at' => Timestamps::toDb($this->clock->now())]);

        return new Iri(Row::string($row, 'agent_iri'));
    }

    /**
     * Registers a client. The caller shows the secret once; only its hash is kept.
     *
     * @throws ClientIdTaken when the id is registered already (the unique key decides, in a savepoint so a
     *                       refused insert does not poison an enclosing PostgreSQL transaction)
     */
    public function create(string $clientId, #[SensitiveParameter] string $clientSecret, Iri $agent, ?string $name = null): void
    {
        $now = Timestamps::toDb($this->clock->now());
        $row = [
            'client_id' => $clientId,
            'secret_hash' => $this->hasher->make($clientSecret),
            'agent_iri' => $agent->value,
            'name' => $name,
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $this->db->transaction(function () use ($row): void {
                $this->db->table($this->tables->clients())->insert($row);
            }, 1);
        } catch (UniqueConstraintViolationException) {
            throw new ClientIdTaken($clientId);
        }
    }

    public function setEnabled(string $clientId, bool $enabled): bool
    {
        return $this->db->table($this->tables->clients())->where('client_id', $clientId)->update(['enabled' => $enabled, 'updated_at' => Timestamps::toDb($this->clock->now())]) === 1;
    }

    private function dummyHash(): string
    {
        $cached = $this->cache->get(self::DUMMY_HASH_KEY);
        if (\is_string($cached) && $cached !== '' && !$this->hasher->needsRehash($cached)) {
            return $cached;
        }
        $hash = $this->hasher->make(bin2hex(random_bytes(16)));
        $this->cache->set(self::DUMMY_HASH_KEY, $hash, 86_400);

        return $hash;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function enabled(array $row): bool
    {
        // PDO drivers disagree on booleans: bool (PostgreSQL), int (MySQL, SQLite) or a string.
        return \in_array($row['enabled'] ?? null, [true, 1, '1', 't', 'true'], true);
    }
}
