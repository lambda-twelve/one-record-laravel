<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\ServerController;
use LambdaTwelve\OneRecord\Server\ServerConfig;

/**
 * Route registration. The server is mounted under ServerConfig::$basePath,
 * always: the SDK strips that prefix from every request itself, so letting a
 * route group choose a different prefix could only break things. Middleware,
 * domain and route-name prefix are the host's to choose, here or in config.
 *
 * @phpstan-type RouteOptions array{middleware?: list<string>|string, domain?: ?string, name?: string}
 */
final class OneRecord
{
    private function __construct() {}

    /**
     * Mounts every ONE Record endpoint. Called by the service provider when
     * `one-record.routes.register` is true; call it yourself otherwise.
     *
     * @param RouteOptions $options overrides for the `one-record.routes` config
     */
    public static function routes(array $options = []): void
    {
        $app = Container::getInstance();
        $router = $app->make(Router::class);
        $config = $app->make(ServerConfig::class);

        $router->group(self::group($app, $options, $config->basePath), static function (Router $router): void {
            $router->any('/{path?}', ServerController::class)->where('path', '.*')->name('server');
        });
        // Routes named after being added are only findable by name once the lookups are rebuilt;
        // Laravel does this after boot, which is too early for a host calling this from a booted app.
        $router->getRoutes()->refreshNameLookups();
    }

    /**
     * @param RouteOptions $options
     * @return array<string, mixed>
     */
    private static function group(Container $app, array $options, string $prefix): array
    {
        $settings = $app->make(Repository::class)->get('one-record.routes', []);
        $settings = \is_array($settings) ? $settings : [];
        $middleware = $options['middleware'] ?? $settings['middleware'] ?? [];
        $name = $options['name'] ?? $settings['name'] ?? 'one-record.';
        $domain = $options['domain'] ?? $settings['domain'] ?? null;

        $group = [
            'prefix' => $prefix,
            'middleware' => \is_string($middleware) ? [$middleware] : (\is_array($middleware) ? array_values(array_map(static fn(mixed $m): string => \is_scalar($m) ? (string) $m : '', $middleware)) : []),
            'as' => \is_string($name) ? $name : 'one-record.',
        ];
        if (\is_string($domain) && $domain !== '') {
            $group['domain'] = $domain;
        }

        return $group;
    }
}
