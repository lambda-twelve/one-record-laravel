<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * A typed wrapper around the connection's transaction helper. Nested calls
 * become savepoints, which is also what makes "insert and catch the unique
 * violation" safe on PostgreSQL inside a request-level transaction.
 */
trait Transactions
{
    abstract protected function connection(): ConnectionInterface;

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        /** @var T */
        return $this->connection()->transaction($callback, 1);
    }
}
