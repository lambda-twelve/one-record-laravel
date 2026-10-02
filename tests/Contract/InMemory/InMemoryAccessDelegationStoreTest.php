<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\InMemory;

use LambdaTwelve\OneRecord\Laravel\Tests\Contract\AccessDelegationStoreContract;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryAccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's reference store under the contract: proves the contract itself
 * describes the reference behaviour before it is asked of the database store.
 */
#[CoversNothing]
final class InMemoryAccessDelegationStoreTest extends TestCase
{
    use AccessDelegationStoreContract;

    private ?InMemoryAccessDelegationStore $store = null;

    protected function delegations(): AccessDelegationStore
    {
        return $this->store ??= new InMemoryAccessDelegationStore();
    }
}
