<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Config;

use InvalidArgumentException;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use LambdaTwelve\OneRecord\Spec\DataModelVersion;

/**
 * Turns the `one-record.server` configuration array into the SDK's
 * ServerConfig. Only translation happens here: the SDK validates the result.
 */
final class ServerConfigFactory
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): ServerConfig
    {
        $baseUrl = self::baseUrl($config['base_url'] ?? null);
        $basePath = self::basePath($config['base_path'] ?? '');
        $endpoint = $baseUrl . $basePath;
        $dataHolderType = $config['data_holder_type'] ?? null;

        return new ServerConfig(
            baseUrl: $baseUrl,
            dataHolder: self::dataHolder($config['data_holder'] ?? null, $endpoint),
            basePath: $basePath,
            apiVersions: self::versions($config['api_versions'] ?? null, 'api_versions', static fn(string $v): ?ApiVersion => ApiVersion::tryFromString($v)),
            dataModelVersions: self::versions($config['data_model_versions'] ?? null, 'data_model_versions', static fn(string $v): ?DataModelVersion => DataModelVersion::tryFromString($v)),
            languages: self::languages($config['languages'] ?? null),
            maxBodyBytes: self::int($config['max_body_bytes'] ?? null, 1_048_576),
            embeddedDepth: self::int($config['embedded_depth'] ?? null, 3),
            bulkLogisticsEvents: (bool) ($config['bulk_logistics_events'] ?? false),
            dataHolderType: \is_string($dataHolderType) && $dataHolderType !== '' ? $dataHolderType : null,
        );
    }

    /**
     * What is wrong with the `one-record.server` array, in plain sentences,
     * without constructing anything: for a status page on an install that is
     * not configured yet. This factory's own translation checks come first
     * (they name the environment variables to set); when they all pass, the
     * SDK's ServerConfig::problems() judges the translated settings. Empty
     * means fromArray() would succeed.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function problems(array $config): array
    {
        $problems = [];
        $settings = [
            'languages' => self::languages($config['languages'] ?? null),
            'maxBodyBytes' => self::int($config['max_body_bytes'] ?? null, 1_048_576),
            'embeddedDepth' => self::int($config['embedded_depth'] ?? null, 3),
        ];
        $endpoint = null;
        try {
            $settings['baseUrl'] = self::baseUrl($config['base_url'] ?? null);
            $settings['basePath'] = self::basePath($config['base_path'] ?? '');
            $endpoint = $settings['baseUrl'] . $settings['basePath'];
        } catch (InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }
        $holder = $config['data_holder'] ?? null;
        // A bare id is placed under the endpoint; with no usable base URL there is nothing to judge about
        // it yet, and the base URL problem above already says what to fix first.
        if ($endpoint !== null || !\is_string($holder) || trim($holder) === '' || str_contains($holder, '://')) {
            try {
                $settings['dataHolder'] = self::dataHolder($holder, $endpoint ?? '')->value;
            } catch (InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }
        foreach (['api_versions' => 'apiVersions', 'data_model_versions' => 'dataModelVersions'] as $key => $setting) {
            try {
                $settings[$setting] = $key === 'api_versions'
                    ? self::versions($config[$key] ?? null, $key, static fn(string $v): ?ApiVersion => ApiVersion::tryFromString($v))
                    : self::versions($config[$key] ?? null, $key, static fn(string $v): ?DataModelVersion => DataModelVersion::tryFromString($v));
            } catch (InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return $problems === [] ? ServerConfig::problems($settings) : $problems;
    }

    /**
     * The supported languages as the SDK wants them; anything but a list is
     * read as the default, the same way for construction and for problems()
     * so the two never disagree (AR8-002).
     *
     * @return list<string>
     */
    private static function languages(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map(static fn(mixed $l): string => \is_scalar($l) ? (string) $l : '', $value)) : ['en-US'];
    }

    private static function int(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /**
     * The SDK wants scheme and host only. APP_URL often carries a path when an
     * application lives in a subdirectory; refusing it (rather than silently
     * dropping the path) makes the operator put the prefix in base_path, where
     * the routes will actually be mounted.
     */
    private static function baseUrl(mixed $value): string
    {
        if (!\is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('one-record.server.base_url must be set (ONE_RECORD_BASE_URL or APP_URL), e.g. https://1r.example.com.');
        }
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException(\sprintf('one-record.server.base_url must be an http(s) URL with a host, got "%s".', $value));
        }
        if (isset($parts['path']) && $parts['path'] !== '' || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw new InvalidArgumentException(\sprintf('one-record.server.base_url must be scheme and host only, got "%s"; put the path prefix in one-record.server.base_path.', $value));
        }

        return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The mounted prefix as the SDK sees it: no trailing slash, a leading one unless empty.
     */
    public static function basePath(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new InvalidArgumentException('one-record.server.base_path must be a string such as "/one-record" (or empty for the root).');
        }
        $value = trim($value, '/');

        return $value === '' ? '' : '/' . $value;
    }

    private static function dataHolder(mixed $value, string $endpoint): Iri
    {
        if (!\is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('one-record.server.data_holder must be set (ONE_RECORD_DATA_HOLDER): the IRI of the organisation holding the data, or an id to place under ' . $endpoint . '/logistics-objects/.');
        }
        $value = trim($value);

        return new Iri(str_contains($value, '://') ? $value : $endpoint . '/logistics-objects/' . ltrim($value, '/'));
    }

    /**
     * @template T of object
     * @param callable(string): ?T $parse
     * @return ?list<T>
     */
    private static function versions(mixed $value, string $key, callable $parse): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (\is_string($value)) {
            $value = explode(',', $value);
        }
        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf('one-record.server.%s must be null, a comma-separated string or a list of version strings.', $key));
        }
        $out = [];
        foreach ($value as $item) {
            $label = \is_scalar($item) ? trim((string) $item) : '';
            $version = $label === '' ? null : $parse($label);
            if ($version === null) {
                throw new InvalidArgumentException(\sprintf('one-record.server.%s contains "%s", which this SDK does not know.', $key, $label));
            }
            $out[] = $version;
        }

        return $out;
    }
}
