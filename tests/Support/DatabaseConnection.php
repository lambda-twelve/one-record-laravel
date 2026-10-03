<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

/**
 * The database connection the store and feature tests run on, from the
 * DB_CONNECTION environment: SQLite in memory by default, MariaDB or
 * PostgreSQL in DDEV and CI. One definition for the Testbench application and
 * for the bare container the SDK's contract tests boot.
 */
final class DatabaseConnection
{
    private function __construct() {}

    public static function name(): string
    {
        return self::env('DB_CONNECTION', 'sqlite');
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $connection = self::name();
        if ($connection === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        return [
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
        ];
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
