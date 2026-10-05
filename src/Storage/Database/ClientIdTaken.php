<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use RuntimeException;

/**
 * A client id is registered once; the table's unique key is the check.
 */
final class ClientIdTaken extends RuntimeException
{
    public function __construct(public readonly string $clientId)
    {
        parent::__construct(\sprintf('A client with id "%s" already exists.', $clientId));
    }
}
