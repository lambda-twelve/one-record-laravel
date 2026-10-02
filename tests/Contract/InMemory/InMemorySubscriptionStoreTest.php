<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\InMemory;

use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\SubscriptionStoreContract;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryActionRequestStore;
use LambdaTwelve\OneRecord\Server\InMemory\InMemorySubscriptionStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemorySubscriptionStoreTest extends TestCase
{
    use SubscriptionStoreContract;

    private ?InMemoryActionRequestStore $requests = null;
    private ?InMemorySubscriptionStore $subscriptions = null;

    protected function requests(): ActionRequestStore
    {
        return $this->requests ??= new InMemoryActionRequestStore();
    }

    protected function subscriptions(): SubscriptionStore
    {
        $requests = $this->requests();
        \assert($requests instanceof InMemoryActionRequestStore);

        return $this->subscriptions ??= new InMemorySubscriptionStore($requests);
    }

    protected function offer(Subscription $subscription): void
    {
        $store = $this->subscriptions();
        \assert($store instanceof InMemorySubscriptionStore);
        $store->offer($subscription);
    }
}
