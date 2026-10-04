<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Illuminate\Database\ConnectionInterface;
use LambdaTwelve\OneRecord\Server\Spi\UnitOfWork;

/**
 * The SDK's transaction boundary on the storage connection. Every operation
 * the SDK runs through its unit of work (a mutating request, a DataHolder or
 * ActionRequests call) becomes one database transaction, and a nested call
 * becomes a savepoint, so an acceptance that writes grants and then loses the
 * status race unwinds its grants before the SDK answers 409. Exceptions roll
 * back and propagate; a return value is committed.
 */
final class DatabaseUnitOfWork implements UnitOfWork
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function run(callable $work): mixed
    {
        return $this->db->transaction(static fn(): mixed => $work(), 1);
    }
}
