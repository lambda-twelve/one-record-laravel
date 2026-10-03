<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Testing\Contract\ActionRequestStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseActionRequestStore::class)]
final class DatabaseActionRequestStoreContractTest extends ActionRequestStoreContract
{
    use UsesTheDatabaseStores;

    protected function createStore(): ActionRequestStore
    {
        return new DatabaseActionRequestStore($this->connection(), $this->tables());
    }
}
