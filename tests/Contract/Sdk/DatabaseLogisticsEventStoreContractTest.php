<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Testing\Contract\LogisticsEventStoreContractTests;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsEventStore::class)]
final class DatabaseLogisticsEventStoreContractTest extends SdkContractTestCase
{
    use LogisticsEventStoreContractTests;

    protected function createStore(): LogisticsEventStore
    {
        $store = $this->app()->make(LogisticsEventStore::class);
        self::assertInstanceOf(DatabaseLogisticsEventStore::class, $store);

        return $store;
    }
}
