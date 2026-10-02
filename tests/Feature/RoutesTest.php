<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Routing\Router;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\ServerController;
use LambdaTwelve\OneRecord\Laravel\OneRecord;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OneRecord::class)]
final class RoutesTest extends TestCase
{
    public function testTheServerIsMountedUnderTheConfiguredBasePath(): void
    {
        $route = $this->app()->make(Router::class)->getRoutes()->getByName('one-record.server');

        self::assertNotNull($route);
        self::assertSame('one-record/{path?}', $route->uri());
        self::assertSame(ServerController::class, $route->getActionName());
        self::assertContains('GET', $route->methods());
        self::assertContains('PATCH', $route->methods());
        self::assertSame(['path' => '.*'], $route->wheres);
        self::assertSame([], $route->gatherMiddleware());
    }

    public function testMiddlewareDomainAndNameComeFromConfig(): void
    {
        $this->config()->set('one-record.routes', ['register' => false, 'middleware' => ['api', 'throttle:10,1'], 'domain' => '1r.test', 'name' => 'cargo.']);
        OneRecord::routes();

        $route = $this->app()->make(Router::class)->getRoutes()->getByName('cargo.server');
        self::assertNotNull($route);
        self::assertSame(['api', 'throttle:10,1'], $route->gatherMiddleware());
        self::assertSame('1r.test', $route->getDomain());
    }

    public function testExplicitRegistrationOverridesConfigButNeverThePrefix(): void
    {
        OneRecord::routes(['middleware' => 'web', 'name' => 'manual.']);

        $route = $this->app()->make(Router::class)->getRoutes()->getByName('manual.server');
        self::assertNotNull($route);
        self::assertSame('one-record/{path?}', $route->uri());
        self::assertSame(['web'], $route->gatherMiddleware());
    }

    public function testRegistrationCanBeLeftToTheApplication(): void
    {
        $this->config()->set('one-record.routes.register', false);
        $this->refreshApplication();

        self::assertNull($this->app()->make(Router::class)->getRoutes()->getByName('one-record.server'));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if ($this->name() === 'testRegistrationCanBeLeftToTheApplication') {
            $app->make(\Illuminate\Contracts\Config\Repository::class)->set('one-record.routes.register', false);
        }
    }
}
