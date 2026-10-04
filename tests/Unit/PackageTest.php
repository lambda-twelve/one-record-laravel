<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Unit;

use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PackageTest extends TestCase
{
    public function testComposerManifestIsConsistent(): void
    {
        $json = file_get_contents(\dirname(__DIR__, 2) . '/composer.json');
        self::assertNotFalse($json);
        /** @var array{name: string, license: string, require: array<string, string>, repositories?: mixed, extra: array{laravel: array{providers: list<string>}}} $manifest */
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('lambda-twelve/one-record-laravel', $manifest['name']);
        self::assertSame('Apache-2.0', $manifest['license']);
        self::assertSame('^8.3', $manifest['require']['php']);
        self::assertStringStartsWith('^1.0.0-beta', $manifest['require']['lambda-twelve/one-record'], 'the SDK comes from Packagist, pre-release allowed');
        self::assertArrayNotHasKey('repositories', $manifest, 'no path or VCS repository: the SDK is a Packagist dependency');
        self::assertSame([OneRecordServiceProvider::class], $manifest['extra']['laravel']['providers']);
    }
}
