<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\DatabaseConnection;

/**
 * The SDK ships its store contracts as PHPUnit test cases, so a test that
 * extends one cannot also extend Testbench's. This boots just what the
 * database stores need: a bare application holding the configuration and a
 * database manager (no providers), the package migration run fresh on the
 * connection DB_CONNECTION names, and the facade the migration uses pointed
 * at that application. The provider's wiring of these stores is covered by
 * the Testbench-based tests.
 */
trait UsesTheDatabaseStores
{
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        $app->instance('config', new Repository([
            'database' => [
                'default' => DatabaseConnection::name(),
                'connections' => [DatabaseConnection::name() => DatabaseConnection::config()],
            ],
        ]));
        $app->singleton('db', static fn(Application $app): DatabaseManager => new DatabaseManager($app, new ConnectionFactory($app)));
        $app->instance(Tables::class, new Tables());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->app = $app;

        // Fresh tables for every test, left in place afterwards: on a shared
        // database the Testbench tests find them where RefreshDatabase expects.
        $migration = require __DIR__ . '/../../../database/migrations/2026_01_01_000000_create_one_record_tables.php';
        \assert(\is_object($migration));
        self::runMigrationStep($migration, 'down');
        self::runMigrationStep($migration, 'up');
    }

    protected function tearDown(): void
    {
        $this->databaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->appOrFail()->flush();
        Container::setInstance(null);
        $this->app = null;

        parent::tearDown();
    }

    protected function connection(): ConnectionInterface
    {
        return $this->databaseManager()->connection();
    }

    protected function tables(): Tables
    {
        $tables = $this->appOrFail()->make(Tables::class);
        \assert($tables instanceof Tables);

        return $tables;
    }

    private function databaseManager(): DatabaseManager
    {
        $manager = $this->appOrFail()->make('db');
        \assert($manager instanceof DatabaseManager);

        return $manager;
    }

    private function appOrFail(): Application
    {
        $app = $this->app;
        \assert($app instanceof Application, 'setUp() has not run');

        return $app;
    }

    private static function runMigrationStep(object $migration, string $method): void
    {
        $step = [$migration, $method];
        \assert(\is_callable($step));
        $step();
    }
}
