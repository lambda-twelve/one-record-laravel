<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Testing\Contract\SubscriptionStoreContractTests;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseSubscriptionStore::class)]
final class DatabaseSubscriptionStoreContractTest extends SdkContractTestCase
{
    use SubscriptionStoreContractTests;

    protected function createStores(): array
    {
        $subscriptions = $this->app()->make(SubscriptionStore::class);
        self::assertInstanceOf(DatabaseSubscriptionStore::class, $subscriptions);

        return [
            'requests' => $this->app()->make(ActionRequestStore::class),
            'subscriptions' => $subscriptions,
        ];
    }
}
