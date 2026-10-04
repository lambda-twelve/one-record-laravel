<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Testing\Contract\ActionRequestStoreContractTests;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseActionRequestStore::class)]
final class DatabaseActionRequestStoreContractTest extends SdkContractTestCase
{
    use ActionRequestStoreContractTests;

    protected function createStore(): ActionRequestStore
    {
        $store = $this->app()->make(ActionRequestStore::class);
        self::assertInstanceOf(DatabaseActionRequestStore::class, $store);

        return $store;
    }
}
