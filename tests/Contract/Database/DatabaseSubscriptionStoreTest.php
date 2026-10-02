<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\SubscriptionStoreContract;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseSubscriptionStore::class)]
final class DatabaseSubscriptionStoreTest extends DatabaseTestCase
{
    use SubscriptionStoreContract;

    protected function requests(): ActionRequestStore
    {
        return $this->app()->make(ActionRequestStore::class);
    }

    protected function subscriptions(): SubscriptionStore
    {
        return $this->app()->make(SubscriptionStore::class);
    }

    protected function offer(Subscription $subscription): void
    {
        $store = $this->subscriptions();
        self::assertInstanceOf(DatabaseSubscriptionStore::class, $store);
        $store->offer($subscription);
    }
}
