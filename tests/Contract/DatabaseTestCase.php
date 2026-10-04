<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;

/**
 * Base for tests of the database stores: the package's migrations on the
 * connection DB_CONNECTION names, fresh for every test. Also the base of the
 * SDK's shipped contract traits in tests/Contract/Sdk, whose fixture
 * constants are prefixed since SDK beta3 and so no longer clash with
 * TestCase's.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }
}
