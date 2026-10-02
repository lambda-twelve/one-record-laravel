<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Auth;

use LambdaTwelve\OneRecord\Auth\Jwt\KeyResolver;

/**
 * Asks several resolvers in turn and answers with the first non-empty key
 * set, so static keys for some issuers and JWKS discovery for others can be
 * configured side by side. (A candidate for the SDK's Auth\Jwt namespace.)
 */
final class ChainKeyResolver implements KeyResolver
{
    /**
     * @param list<KeyResolver> $resolvers
     */
    public function __construct(private readonly array $resolvers) {}

    public function publicKeys(string $issuer, ?string $keyId): array
    {
        foreach ($this->resolvers as $resolver) {
            $keys = $resolver->publicKeys($issuer, $keyId);
            if ($keys !== []) {
                return $keys;
            }
        }

        return [];
    }
}
