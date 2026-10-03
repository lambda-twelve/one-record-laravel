<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseAccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Testing\Contract\AccessDelegationStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseAccessDelegationStore::class)]
final class DatabaseAccessDelegationStoreContractTest extends AccessDelegationStoreContract
{
    use UsesTheDatabaseStores;

    protected function createStore(): AccessDelegationStore
    {
        return new DatabaseAccessDelegationStore($this->connection(), $this->tables());
    }
}
