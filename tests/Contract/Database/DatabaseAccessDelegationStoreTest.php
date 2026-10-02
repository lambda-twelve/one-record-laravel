<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseAccessDelegationStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\AccessDelegationStoreContract;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseAccessDelegationStore::class)]
final class DatabaseAccessDelegationStoreTest extends DatabaseTestCase
{
    use AccessDelegationStoreContract;

    protected function delegations(): AccessDelegationStore
    {
        $store = $this->app()->make(AccessDelegationStore::class);
        self::assertInstanceOf(DatabaseAccessDelegationStore::class, $store);

        return $store;
    }
}
