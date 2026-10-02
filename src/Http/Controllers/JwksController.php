<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LambdaTwelve\OneRecord\Auth\Jwt\Rs256Signer;

/**
 * Publishes the signing key of the token endpoint as a JWKS document, so
 * partners (and other ONE Record servers that trust this host as an issuer)
 * can verify the tokens it issues. The SDK exposes the key; serving it is the
 * host's job.
 */
final class JwksController
{
    public function __construct(private readonly Rs256Signer $signer) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['keys' => [$this->signer->publicJwk()]], 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
