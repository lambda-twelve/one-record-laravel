<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\DatabaseConnection;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Base case for everything that needs a Laravel application. The database
 * comes from the DB_CONNECTION environment variable so the same suite runs on
 * SQLite locally and on MariaDB/PostgreSQL in DDEV and CI.
 */
abstract class TestCase extends Testbench
{
    public const string BASE = 'https://1r.test';
    public const string BASE_PATH = '/one-record';
    public const string HOLDER = 'https://1r.test/one-record/logistics-objects/holder';
    public const string PARTNER = 'https://partner.example/logistics-objects/partner';
    public const string STRANGER = 'https://stranger.example/logistics-objects/stranger';

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
        // Not whatever a generated Testbench .env says: the unique-job lock needs a cache store that exists.
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('app.url', self::BASE);
        $config->set('one-record.server.base_url', self::BASE);
        $config->set('one-record.server.base_path', self::BASE_PATH);
        $config->set('one-record.server.data_holder', self::HOLDER);
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
