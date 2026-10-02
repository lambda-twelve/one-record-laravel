<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use LambdaTwelve\OneRecord\Auth\ClientCredentialsVerifier;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Rdf\Iri;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * The partners this host issues tokens to, with secrets hashed by Laravel's
 * configured hasher. Like the SDK's in-memory verifier it always performs
 * exactly one hash check (against a throwaway hash when the client id is
 * unknown), so an attacker cannot tell valid ids from invalid ones by timing.
 */
final class DatabaseClientCredentials implements ClientCredentialsVerifier
{
    private ?string $dummyHash = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
        private readonly Hasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    public function verify(string $clientId, string $clientSecret): ?Iri
    {
        $row = Row::first($this->db->table($this->tables->clients())->where('client_id', $clientId));
        $valid = $this->hasher->check($clientSecret, $row === null ? $this->dummyHash() : Row::string($row, 'secret_hash'));
        if ($row === null || !$valid || !self::enabled($row)) {
            return null;
        }
        $this->db->table($this->tables->clients())->where('client_id', $clientId)->update(['last_used_at' => Timestamps::toDb($this->clock->now())]);

        return new Iri(Row::string($row, 'agent_iri'));
    }

    /**
     * Registers a client. The caller shows the secret once; only its hash is kept.
     */
    public function create(string $clientId, #[SensitiveParameter] string $clientSecret, Iri $agent, ?string $name = null): void
    {
        $now = Timestamps::toDb($this->clock->now());
        $this->db->table($this->tables->clients())->insert([
            'client_id' => $clientId,
            'secret_hash' => $this->hasher->make($clientSecret),
            'agent_iri' => $agent->value,
            'name' => $name,
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function setEnabled(string $clientId, bool $enabled): bool
    {
        return $this->db->table($this->tables->clients())->where('client_id', $clientId)->update(['enabled' => $enabled, 'updated_at' => Timestamps::toDb($this->clock->now())]) === 1;
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->make(bin2hex(random_bytes(16)));
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
