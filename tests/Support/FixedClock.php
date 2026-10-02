<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * A clock the tests move by hand. Millisecond-aligned instants keep database
 * and in-memory stores byte-identical through JSON-LD round trips.
 */
final class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string|DateTimeImmutable $now = '2026-10-02T12:00:00.250Z')
    {
        $this->now = ($now instanceof DateTimeImmutable ? $now : new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }

    public function set(string $now): void
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }
}
