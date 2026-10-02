<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use RuntimeException;

/**
 * RSA key pairs for tests, generated once per process: key generation is the
 * slow part, and the keys carry no secret worth protecting.
 */
final class TestKeys
{
    /** @var array<string, array{private: string, public: string}> */
    private static array $pairs = [];

    /**
     * @return array{private: string, public: string}
     */
    public static function pair(string $name = 'default'): array
    {
        if (!isset(self::$pairs[$name])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || !openssl_pkey_export($key, $private)) {
                throw new RuntimeException('Cannot generate a test key.');
            }
            $details = openssl_pkey_get_details($key);
            if ($details === false || !\is_string($details['key'] ?? null) || !\is_string($private)) {
                throw new RuntimeException('Cannot read the test key.');
            }
            self::$pairs[$name] = ['private' => $private, 'public' => $details['key']];
        }

        return self::$pairs[$name];
    }
}
