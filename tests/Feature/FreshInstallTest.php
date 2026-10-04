<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use LambdaTwelve\OneRecord\Laravel\OneRecord;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Finding 3 of the adversarial review: a freshly installed application has
 * no ONE_RECORD_DATA_HOLDER yet, and the provider used to resolve the whole
 * validated ServerConfig while mounting the routes, so nothing booted, not
 * even `artisan` to publish the configuration or run the migrations.
 */
#[CoversClass(OneRecordServiceProvider::class)]
#[CoversClass(OneRecord::class)]
final class FreshInstallTest extends TestCase
{
    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // As shipped: nothing published, ONE_RECORD_DATA_HOLDER unset.
        $app->make(Repository::class)->set('one-record.server.data_holder', null);
    }

    public function testTheApplicationBootsAndTheConsoleWorksBeforeTheServerIsConfigured(): void
    {
        self::assertNotNull($this->app()->make(Router::class)->getRoutes()->getByName('one-record.server'), 'routes mount from the base path alone');

        $list = $this->artisan('list');
        self::assertInstanceOf(PendingCommand::class, $list);
        $list->expectsOutputToContain('one-record:outbox:deliver')->assertSuccessful()->run();
    }

    public function testTheMissingDataHolderIsReportedWhenTheServerIsFirstNeeded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one-record.server.data_holder must be set');

        $this->app()->make(ServerConfig::class);
    }
}
