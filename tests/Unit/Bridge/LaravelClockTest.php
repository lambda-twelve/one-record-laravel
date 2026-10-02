<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Unit\Bridge;

use Carbon\CarbonImmutable;
use LambdaTwelve\OneRecord\Laravel\Bridge\LaravelClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaravelClock::class)]
final class LaravelClockTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function testFollowsCarbonTestTimeInUtcTruncatedToMilliseconds(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02T14:30:15.123456+02:00'));

        $now = (new LaravelClock())->now();

        self::assertSame('2026-10-02T12:30:15.123000+00:00', $now->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testReturnsTheRealTimeWhenNotTravelling(): void
    {
        $before = time();
        $now = (new LaravelClock())->now();

        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getTimestamp());
        self::assertSame(0, (int) $now->format('u') % 1000);
    }
}
