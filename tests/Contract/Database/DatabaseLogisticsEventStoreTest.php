<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsEventStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\LogisticsEventStoreContract;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsEventStore::class)]
final class DatabaseLogisticsEventStoreTest extends DatabaseTestCase
{
    use LogisticsEventStoreContract;

    protected function events(): LogisticsEventStore
    {
        $store = $this->app()->make(LogisticsEventStore::class);
        self::assertInstanceOf(DatabaseLogisticsEventStore::class, $store);

        return $store;
    }
}
