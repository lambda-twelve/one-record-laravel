<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;

/**
 * Base for tests of the database stores: the package's migrations on the
 * connection DB_CONNECTION names, fresh for every test.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }
}
