<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use LambdaTwelve\OneRecord\Laravel\Http\PsrBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsrBridge::class)]
final class PsrBridgeTest extends TestCase
{
    public function testTheRequestKeepsEverythingTheSdkReads(): void
    {
        $request = Request::create(
            'https://1r.test/one-record/logistics-objects/piece-1?embedded=true',
            'PATCH',
            server: ['HTTP_ACCEPT' => 'application/ld+json; version=2.3.0', 'CONTENT_TYPE' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer t', 'SERVER_PROTOCOL' => 'HTTP/2.0'],
            content: '{"@id": "x"}',
        );

        $psr = (new PsrBridge())->toServerRequest($request);

        self::assertSame('PATCH', $psr->getMethod());
        self::assertSame('/one-record/logistics-objects/piece-1', $psr->getUri()->getPath());
        self::assertSame('1r.test', $psr->getUri()->getHost());
        self::assertSame(['embedded' => 'true'], $psr->getQueryParams());
        self::assertSame('application/ld+json; version=2.3.0', $psr->getHeaderLine('Accept'));
        self::assertSame('Bearer t', $psr->getHeaderLine('Authorization'));
        self::assertSame('2.0', $psr->getProtocolVersion());
        self::assertSame('{"@id": "x"}', (string) $psr->getBody());
        self::assertNull($psr->getParsedBody());
    }

    public function testFormBodiesArriveParsedForTheTokenEndpoint(): void
    {
        $request = Request::create('https://1r.test/oauth/token', 'POST', ['grant_type' => 'client_credentials', 'client_id' => 'c']);

        $psr = (new PsrBridge())->toServerRequest($request);

        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'c'], $psr->getParsedBody());
    }

    public function testTheResponseCarriesStatusHeadersAndBody(): void
    {
        $response = (new PsrBridge())->toResponse(new Response(201, ['Location' => 'https://1r.test/one-record/action-requests/1', 'Content-Type' => 'application/ld+json; version=2.3.0'], '{}'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('https://1r.test/one-record/action-requests/1', $response->headers->get('Location'));
        self::assertSame('application/ld+json; version=2.3.0', $response->headers->get('Content-Type'));
        self::assertSame('{}', $response->getContent());
    }
}
