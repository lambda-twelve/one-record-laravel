<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Auth;

use Closure;
use InvalidArgumentException;
use LambdaTwelve\OneRecord\Auth\Jwt\JwksKeyResolver;
use LambdaTwelve\OneRecord\Auth\Jwt\KeyResolver;
use LambdaTwelve\OneRecord\Auth\Jwt\Rs256Verifier;
use LambdaTwelve\OneRecord\Auth\Jwt\StaticKeyResolver;
use LambdaTwelve\OneRecord\Auth\JwtAuthenticator;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds the SDK's JWT authenticator from the `one-record.auth` array: one
 * static key resolver for issuers configured with PEMs, one JWKS resolver for
 * issuers configured with a discovery URL, chained. The cache is resolved
 * lazily so hosts without JWKS issuers never touch it.
 */
final class AuthenticatorFactory
{
    /**
     * @param Closure(): CacheInterface $cache
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly Closure $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $auth
     */
    public function make(array $auth): Authenticator
    {
        $audience = $auth['audience'] ?? null;
        $leeway = $auth['leeway'] ?? 30;
        $verifier = new Rs256Verifier(
            $this->keyResolver($auth),
            $this->clock,
            \is_string($audience) && $audience !== '' ? $audience : null,
            is_numeric($leeway) ? (int) $leeway : 30,
        );

        return new JwtAuthenticator($verifier, $this->logger);
    }

    /**
     * @param array<string, mixed> $auth
     */
    public function keyResolver(array $auth): KeyResolver
    {
        $issuers = $auth['issuers'] ?? [];
        if (!\is_array($issuers)) {
            throw new InvalidArgumentException('one-record.auth.issuers must be an array of issuer => ["keys" => [...]] or ["jwks" => true|url].');
        }
        $static = [];
        $jwks = [];
        foreach ($issuers as $issuer => $settings) {
            if (!\is_string($issuer) || $issuer === '') {
                throw new InvalidArgumentException('one-record.auth.issuers keys must be issuer URLs.');
            }
            if (\is_string($settings)) {
                $static[$issuer] = $settings;   // a single PEM, the shortest form
                continue;
            }
            if (!\is_array($settings)) {
                throw new InvalidArgumentException(\sprintf('one-record.auth.issuers["%s"] must be a PEM string or an array with "keys" or "jwks".', $issuer));
            }
            if (isset($settings['keys'])) {
                $keys = $settings['keys'];
                if (!\is_string($keys) && !\is_array($keys)) {
                    throw new InvalidArgumentException(\sprintf('one-record.auth.issuers["%s"]["keys"] must be a PEM, a list of PEMs or a kid => PEM map.', $issuer));
                }
                /** @var string|list<string>|array<string, string> $keys */
                $static[$issuer] = $keys;
            }
            if (isset($settings['jwks'])) {
                $url = $settings['jwks'];
                if ($url === true) {
                    $jwks[$issuer] = null;   // the SDK derives {issuer}/.well-known/jwks.json
                } elseif (\is_string($url) && $url !== '') {
                    $jwks[$issuer] = $url;
                } elseif ($url !== false) {
                    throw new InvalidArgumentException(\sprintf('one-record.auth.issuers["%s"]["jwks"] must be true or a URL.', $issuer));
                }
            }
        }

        $resolvers = [];
        if ($static !== []) {
            $resolvers[] = new StaticKeyResolver($static);
        }
        if ($jwks !== []) {
            $ttl = $auth['jwks_ttl'] ?? 3600;
            $resolvers[] = new JwksKeyResolver($jwks, $this->http, $this->requests, ($this->cache)(), is_numeric($ttl) ? (int) $ttl : 3600, $this->logger);
        }

        return match (\count($resolvers)) {
            0 => new StaticKeyResolver([]),   // no trusted issuer: every token is refused, which is the safe default
            1 => $resolvers[0],
            default => new ChainKeyResolver($resolvers),
        };
    }
}
