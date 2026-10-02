<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\ActionRequestStoreContract;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseActionRequestStore::class)]
final class DatabaseActionRequestStoreTest extends DatabaseTestCase
{
    use ActionRequestStoreContract;

    protected function requests(): ActionRequestStore
    {
        $store = $this->app()->make(ActionRequestStore::class);
        self::assertInstanceOf(DatabaseActionRequestStore::class, $store);

        return $store;
    }
}
