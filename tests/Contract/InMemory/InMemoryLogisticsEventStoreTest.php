<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\InMemory;

use LambdaTwelve\OneRecord\Laravel\Tests\Contract\LogisticsEventStoreContract;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryLogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's reference store under the contract: proves the contract itself
 * describes the reference behaviour before it is asked of the database store.
 */
#[CoversNothing]
final class InMemoryLogisticsEventStoreTest extends TestCase
{
    use LogisticsEventStoreContract;

    private ?InMemoryLogisticsEventStore $store = null;

    protected function events(): LogisticsEventStore
    {
        return $this->store ??= new InMemoryLogisticsEventStore();
    }
}
