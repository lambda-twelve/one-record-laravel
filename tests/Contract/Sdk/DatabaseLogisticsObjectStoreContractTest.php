<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsObjectStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Testing\Contract\LogisticsObjectStoreContractTests;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsObjectStore::class)]
final class DatabaseLogisticsObjectStoreContractTest extends DatabaseTestCase
{
    use LogisticsObjectStoreContractTests;

    protected function createStore(): LogisticsObjectStore
    {
        $store = $this->app()->make(LogisticsObjectStore::class);
        self::assertInstanceOf(DatabaseLogisticsObjectStore::class, $store);

        return $store;
    }
}
