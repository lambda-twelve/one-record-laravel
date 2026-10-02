<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\InMemory;

use LambdaTwelve\OneRecord\Laravel\Tests\Contract\ActionRequestStoreContract;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's reference store under the contract: proves the contract itself
 * describes the reference behaviour before it is asked of the database store.
 */
#[CoversNothing]
final class InMemoryActionRequestStoreTest extends TestCase
{
    use ActionRequestStoreContract;

    private ?InMemoryActionRequestStore $store = null;

    protected function requests(): ActionRequestStore
    {
        return $this->store ??= new InMemoryActionRequestStore();
    }
}
