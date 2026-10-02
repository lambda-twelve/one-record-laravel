<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\JwksController;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\ServerController;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\TokenController;
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
     * Mounts the token endpoint (POST, client-credentials grant) and the JWKS
     * document, each only when enabled in `one-record.auth`. They sit outside
     * the server's base path, at the configured absolute paths.
     *
     * @param RouteOptions $options overrides for the `one-record.routes` config (the token route adds its own throttle middleware from config)
     */
    public static function tokenRoutes(array $options = []): void
    {
        $app = Container::getInstance();
        $router = $app->make(Router::class);
        $auth = $app->make(Repository::class)->get('one-record.auth', []);
        $auth = \is_array($auth) ? $auth : [];
        $token = \is_array($auth['token_endpoint'] ?? null) ? $auth['token_endpoint'] : [];
        $jwks = \is_array($auth['jwks'] ?? null) ? $auth['jwks'] : [];

        $router->group(self::group($app, $options, ''), static function (Router $router) use ($token, $jwks): void {
            if (($token['enabled'] ?? false) === true) {
                $middleware = $token['middleware'] ?? [];
                $router->post(\is_string($token['path'] ?? null) ? $token['path'] : '/oauth/token', TokenController::class)
                    ->middleware(\is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : [])
                    ->name('token');
            }
            if (($jwks['enabled'] ?? false) === true) {
                $router->get(\is_string($jwks['path'] ?? null) ? $jwks['path'] : '/.well-known/jwks.json', JwksController::class)->name('jwks');
            }
        });
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
