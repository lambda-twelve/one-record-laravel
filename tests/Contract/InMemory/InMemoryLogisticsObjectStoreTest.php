<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\InMemory;

use LambdaTwelve\OneRecord\Laravel\Tests\Contract\LogisticsObjectStoreContract;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryLogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's reference store under the contract: proves the contract itself
 * describes the reference behaviour before it is asked of the database store.
 */
#[CoversNothing]
final class InMemoryLogisticsObjectStoreTest extends TestCase
{
    use LogisticsObjectStoreContract;

    private ?InMemoryLogisticsObjectStore $store = null;

    protected function objects(): LogisticsObjectStore
    {
        return $this->store ??= new InMemoryLogisticsObjectStore();
    }
}
