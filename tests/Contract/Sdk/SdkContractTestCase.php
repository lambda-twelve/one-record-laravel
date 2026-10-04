<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\BootsThePackage;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Base for the SDK's shipped store contracts (the `*ContractTests` traits of
 * LambdaTwelve\OneRecord\Testing\Contract) run against the database stores as
 * the provider wires them: a Testbench application on the connection
 * DB_CONNECTION names, the package's migrations fresh for every test. Not the
 * package's TestCase, because the SDK's traits declare PARTNER and HOLDER
 * constants of their own and PHP refuses a class whose constants differ.
 */
abstract class SdkContractTestCase extends Testbench
{
    use BootsThePackage;
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }
}
