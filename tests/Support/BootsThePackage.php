<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;

/**
 * The Testbench environment every test case of this package shares: the
 * provider, the database from DB_CONNECTION, the server identity. A trait
 * rather than only a base class because the SDK's contract traits declare
 * constants (PARTNER, HOLDER) that collide with TestCase's, so the test cases
 * that use them need a base class of their own.
 */
trait BootsThePackage
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OneRecordServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('database.default', DatabaseConnection::name());
        $config->set('database.connections.' . DatabaseConnection::name(), DatabaseConnection::config());
        $config->set('logging.default', 'null');
        $config->set('app.url', TestCase::BASE);
        $config->set('one-record.server.base_url', TestCase::BASE);
        $config->set('one-record.server.base_path', TestCase::BASE_PATH);
        $config->set('one-record.server.data_holder', TestCase::HOLDER);
        $config->set('one-record.storage.driver', $this->storageDriver());
    }

    /**
     * The booted application, typed (Testbench declares it nullable).
     */
    protected function app(): Application
    {
        $app = $this->app;
        self::assertInstanceOf(Application::class, $app);

        return $app;
    }

    protected function config(): Repository
    {
        return $this->app()->make(Repository::class);
    }

    /**
     * The SDK's in-memory stores unless a test case is about the database ones.
     */
    protected function storageDriver(): string
    {
        return 'array';
    }
}
