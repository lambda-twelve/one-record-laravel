<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests;

use LambdaTwelve\OneRecord\Laravel\Tests\Support\BootsThePackage;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Base case for everything that needs a Laravel application. The database
 * comes from the DB_CONNECTION environment variable so the same suite runs on
 * SQLite locally and on MariaDB/PostgreSQL in DDEV and CI.
 */
abstract class TestCase extends Testbench
{
    use BootsThePackage;

    public const string BASE = 'https://1r.test';
    public const string BASE_PATH = '/one-record';
    public const string HOLDER = 'https://1r.test/one-record/logistics-objects/holder';
    public const string PARTNER = 'https://partner.example/logistics-objects/partner';
    public const string STRANGER = 'https://stranger.example/logistics-objects/stranger';
}
