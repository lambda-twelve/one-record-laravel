<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Laravel\Http\TransactionalRequestHandler;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TransactionalRequestHandler::class)]
#[CoversClass(DatabaseLogisticsObjectStore::class)]
final class DatabaseServerFlowTest extends ServerFlowTestCase
{
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }

    public function testTheDatabaseStoresAreInUse(): void
    {
        self::assertInstanceOf(DatabaseLogisticsObjectStore::class, $this->app()->make(LogisticsObjectStore::class));
    }
}
