<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Timestamps go to the database as fixed-width UTC strings with microseconds.
 * Formatting them here rather than binding DateTime objects keeps the
 * precision identical on SQLite, MariaDB and PostgreSQL, and fixed-width
 * strings also compare correctly where the column is text (SQLite).
 */
final class Timestamps
{
    private const string FORMAT = 'Y-m-d H:i:s.u';

    private function __construct() {}

    public static function toDb(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function fromDb(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }
        if (!\is_string($value) || $value === '') {
            throw new InvalidArgumentException('Expected a timestamp string from the database.');
        }

        // PostgreSQL trims trailing zeros from fractions; DateTimeImmutable parses either form.
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function fromDbNullable(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::fromDb($value);
    }
}
