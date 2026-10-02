<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\Agent;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trusts an X-Test-Agent header, like the SDK's own test suite does. Tests of
 * routing and store behaviour do not need real tokens; the JWT path has its
 * own tests.
 */
final class HeaderAuthenticator implements Authenticator
{
    public const string HEADER = 'X-Test-Agent';

    public function authenticate(ServerRequestInterface $request): ?Agent
    {
        $iri = $request->getHeaderLine(self::HEADER);

        return $iri === '' ? null : new Agent(new Iri($iri), 'https://test.issuer', ['sub' => $iri]);
    }
}
