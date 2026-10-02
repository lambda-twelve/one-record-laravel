<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsObjectStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\LogisticsObjectStoreContract;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsObjectStore::class)]
final class DatabaseLogisticsObjectStoreTest extends DatabaseTestCase
{
    use LogisticsObjectStoreContract;

    protected function objects(): LogisticsObjectStore
    {
        $store = $this->app()->make(LogisticsObjectStore::class);
        self::assertInstanceOf(DatabaseLogisticsObjectStore::class, $store);

        return $store;
    }
}
