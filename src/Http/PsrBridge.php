<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Http;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Converts between Laravel's request/response and PSR-7, which is all the
 * SDK's PSR-15 handlers speak. Kept deliberately small (method, URI, headers,
 * raw body, parsed form body) and built on Guzzle's PSR-7, which Laravel
 * already ships, so applications need no extra bridge package.
 */
final class PsrBridge
{
    public function toServerRequest(Request $request): ServerRequestInterface
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = array_values(array_filter($values, static fn(?string $v): bool => $v !== null));
        }
        $body = $request->getContent(true);
        $protocol = $request->server->get('SERVER_PROTOCOL');

        $psr = new ServerRequest(
            $request->getMethod(),
            $request->getUri(),   // the full URL: the SDK strips its own base path from the absolute path
            $headers,
            Utils::streamFor(\is_resource($body) ? $body : (string) $body),
            \is_string($protocol) && str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : '1.1',
            $request->server->all(),
        );
        $psr = $psr->withQueryParams($request->query->all())->withCookieParams($request->cookies->all());
        if ($request->request->count() > 0) {
            // Form bodies (the token endpoint's client-credentials grant) arrive already parsed.
            $psr = $psr->withParsedBody($request->request->all());
        }

        return $psr;
    }

    public function toResponse(ResponseInterface $response): Response
    {
        return new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders());
    }
}
