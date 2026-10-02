<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Bridge;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock over Carbon, so Laravel's time travelling in tests
 * (`$this->travelTo()`, `Carbon::setTestNow()`) reaches the SDK.
 *
 * Always UTC and truncated to whole milliseconds: the SDK writes xsd:dateTime
 * literals with millisecond precision, so a timestamp that survives a JSON-LD
 * round trip must not carry microseconds in the first place.
 */
final class LaravelClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'), new DateTimeZone('UTC'));
    }
}
