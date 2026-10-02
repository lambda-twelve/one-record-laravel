<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\TestResponse;
use LambdaTwelve\OneRecord\Auth\Jwt\Rs256Signer;
use LambdaTwelve\OneRecord\Laravel\Auth\AuthenticatorFactory;
use LambdaTwelve\OneRecord\Laravel\Auth\ChainKeyResolver;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\TestKeys;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Partners' RS256 bearer tokens, verified by the SDK with keys the host
 * configured: static PEMs for one issuer, JWKS discovery for another.
 */
#[CoversClass(AuthenticatorFactory::class)]
#[CoversClass(ChainKeyResolver::class)]
final class JwtAuthenticationTest extends TestCase
{
    private const string STATIC_ISSUER = 'https://auth.partner.example';
    private const string JWKS_ISSUER = 'https://auth.other.example';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('one-record.auth', [
            'driver' => 'jwt',
            'audience' => 'one-record',
            'leeway' => 0,
            'jwks_ttl' => 3600,
            'issuers' => [
                self::STATIC_ISSUER => ['keys' => [TestKeys::pair('partner')['public']]],
                self::JWKS_ISSUER => ['jwks' => true],
            ],
            'token_endpoint' => ['enabled' => false],
            'jwks' => ['enabled' => false],
        ]);
    }

    public function testATokenFromATrustedIssuerAuthenticatesThePartner(): void
    {
        $this->serverInformation($this->token(self::STATIC_ISSUER, 'partner'))->assertOk();
    }

    public function testTokensAreRefusedWhenTheIssuerKeyAudienceOrAgentIsWrong(): void
    {
        $this->serverInformation($this->token('https://auth.unknown.example', 'partner'))->assertStatus(401);
        $this->serverInformation($this->token(self::STATIC_ISSUER, 'impostor'))->assertStatus(401);
        $this->serverInformation($this->token(self::STATIC_ISSUER, 'partner', audience: 'something-else'))->assertStatus(401);
        $this->serverInformation($this->token(self::STATIC_ISSUER, 'partner', agent: null))->assertStatus(401);
        $this->serverInformation($this->token(self::STATIC_ISSUER, 'partner', ttl: -60))->assertStatus(401);
        $this->serverInformation('not.a.jwt')->assertStatus(401);
        $this->serverInformation(null)->assertStatus(401);
    }

    public function testIssuersWithJwksDiscoveryAreFetchedThroughThePsr18Client(): void
    {
        $signer = new Rs256Signer(TestKeys::pair('other')['private'], self::JWKS_ISSUER, $this->app()->make(ClockInterface::class), 'other-key-1');
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode(['keys' => [$signer->publicJwk()]], JSON_THROW_ON_ERROR))]);
        $this->app()->instance(ClientInterface::class, new Client(['handler' => HandlerStack::create($mock)]));

        $this->serverInformation($signer->sign(['logistics_agent_uri' => self::PARTNER, 'aud' => 'one-record'], 300))->assertOk();

        $request = $mock->getLastRequest();
        self::assertNotNull($request);
        self::assertSame(self::JWKS_ISSUER . '/.well-known/jwks.json', (string) $request->getUri());
        // The document is cached: a second token needs no second fetch (the mock queue is empty now).
        $this->serverInformation($signer->sign(['logistics_agent_uri' => self::PARTNER, 'aud' => 'one-record'], 300))->assertOk();
    }

    private function token(string $issuer, string $keyPair, ?string $audience = 'one-record', ?string $agent = self::PARTNER, int $ttl = 300): string
    {
        $signer = new Rs256Signer(TestKeys::pair($keyPair)['private'], $issuer, $this->app()->make(ClockInterface::class));
        $claims = [];
        if ($agent !== null) {
            $claims['logistics_agent_uri'] = $agent;
        }
        if ($audience !== null) {
            $claims['aud'] = $audience;
        }

        return $signer->sign($claims, $ttl);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function serverInformation(?string $bearer): TestResponse
    {
        $server = ['HTTP_ACCEPT' => 'application/ld+json; version=2.3.0'];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }

        return $this->call('GET', self::BASE . '/one-record', [], [], [], $server);
    }
}
