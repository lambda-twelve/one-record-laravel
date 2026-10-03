<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Testing\Contract\SubscriptionStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseSubscriptionStore::class)]
final class DatabaseSubscriptionStoreContractTest extends SubscriptionStoreContract
{
    use UsesTheDatabaseStores;

    protected function createStores(): array
    {
        return [
            'requests' => new DatabaseActionRequestStore($this->connection(), $this->tables()),
            'subscriptions' => new DatabaseSubscriptionStore($this->connection(), $this->tables()),
        ];
    }
}
