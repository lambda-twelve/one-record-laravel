<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
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
        $connection = self::env('DB_CONNECTION', 'sqlite');
        $config->set('database.default', $connection);
        if ($connection === 'sqlite') {
            $config->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        } else {
            $config->set('database.connections.' . $connection, [
                'driver' => $connection,
                'host' => self::env('DB_HOST', '127.0.0.1'),
                'port' => self::env('DB_PORT', $connection === 'pgsql' ? '5432' : '3306'),
                'database' => self::env('DB_DATABASE', 'db'),
                'username' => self::env('DB_USERNAME', 'db'),
                'password' => self::env('DB_PASSWORD', 'db'),
                'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $connection === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]);
        }
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

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
