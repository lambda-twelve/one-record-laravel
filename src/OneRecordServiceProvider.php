<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Wires lambda-twelve/one-record into Laravel's container, routes and console.
 * Everything protocol-related stays in the SDK; this provider only translates
 * Laravel configuration and services into the SDK's constructor arguments.
 */
final class OneRecordServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
