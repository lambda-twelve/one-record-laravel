<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Testing\Contract\LogisticsObjectStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsObjectStore::class)]
final class DatabaseLogisticsObjectStoreContractTest extends LogisticsObjectStoreContract
{
    use UsesTheDatabaseStores;

    protected function createStore(): LogisticsObjectStore
    {
        return new DatabaseLogisticsObjectStore($this->connection(), $this->tables());
    }
}
