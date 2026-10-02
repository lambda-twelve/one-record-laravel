<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LambdaTwelve\OneRecord\Auth\TokenEndpoint;
use LambdaTwelve\OneRecord\Laravel\Http\PsrBridge;

/**
 * POST /oauth/token: the SDK's client-credentials token endpoint behind a
 * Laravel route, so partners of this host obtain their bearer tokens here.
 * Rate limiting is the route's middleware (throttle), not the endpoint's job.
 */
final class TokenController
{
    public function __construct(
        private readonly TokenEndpoint $endpoint,
        private readonly PsrBridge $bridge,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->bridge->toResponse($this->endpoint->handle($this->bridge->toServerRequest($request)));
    }
}
