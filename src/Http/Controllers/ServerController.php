<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LambdaTwelve\OneRecord\Laravel\Http\PsrBridge;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The one route behind every ONE Record endpoint: Laravel request in, SDK
 * server, Laravel response out. The handler is whatever the service provider
 * bound for this controller (the SDK server, wrapped in a transaction when
 * the database stores are in use).
 */
final class ServerController
{
    public function __construct(
        private readonly RequestHandlerInterface $server,
        private readonly PsrBridge $bridge,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->bridge->toResponse($this->server->handle($this->bridge->toServerRequest($request)));
    }
}
