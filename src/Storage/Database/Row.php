<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

/**
 * Typed access to query-builder rows. The builder returns stdClass objects;
 * reading them as arrays with explicit casts keeps the stores honest about
 * what the database gave back.
 */
final class Row
{
    private function __construct() {}

    /**
     * @return ?array<string, mixed>
     */
    public static function first(Builder $query): ?array
    {
        $row = $query->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> */
        return (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(Builder $query): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(array_map(static fn(mixed $row): array => (array) $row, $query->get()->all()));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (\is_string($value)) {
            return $value;
        }
        if (\is_resource($value)) {   // PostgreSQL may hand large text back as a stream
            $contents = stream_get_contents($value);
            if ($contents !== false) {
                return $contents;
            }
        }

        throw new UnexpectedValueException(\sprintf('Column "%s" should hold a string.', $column));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::string($row, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException(\sprintf('Column "%s" should hold an integer.', $column));
    }
}
