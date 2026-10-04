<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseAccessDelegationStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Testing\Contract\AccessDelegationStoreContractTests;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseAccessDelegationStore::class)]
final class DatabaseAccessDelegationStoreContractTest extends DatabaseTestCase
{
    use AccessDelegationStoreContractTests;

    protected function createStore(): AccessDelegationStore
    {
        $store = $this->app()->make(AccessDelegationStore::class);
        self::assertInstanceOf(DatabaseAccessDelegationStore::class, $store);

        return $store;
    }
}
