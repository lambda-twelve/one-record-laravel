<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Testing\Contract\LogisticsEventStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsEventStore::class)]
final class DatabaseLogisticsEventStoreContractTest extends LogisticsEventStoreContract
{
    use UsesTheDatabaseStores;

    protected function createStore(): LogisticsEventStore
    {
        return new DatabaseLogisticsEventStore($this->connection(), $this->tables());
    }
}
