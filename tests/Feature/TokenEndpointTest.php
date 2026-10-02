<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use LambdaTwelve\OneRecord\Auth\ClientCredentialsVerifier;
use LambdaTwelve\OneRecord\Laravel\Console\CreateClientCommand;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\JwksController;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\TokenController;
use LambdaTwelve\OneRecord\Laravel\OneRecord;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseClientCredentials;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\TestKeys;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Rdf\Iri;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * This host as an issuer: a partner registered in the database obtains a
 * token from /oauth/token and the server accepts it, because the host trusts
 * its own issuer through the configured keys; the JWKS route publishes them.
 */
#[CoversClass(TokenController::class)]
#[CoversClass(JwksController::class)]
#[CoversClass(DatabaseClientCredentials::class)]
#[CoversClass(CreateClientCommand::class)]
#[CoversClass(OneRecord::class)]
final class TokenEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const string ISSUER = 'https://1r.test';

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('one-record.auth', [
            'driver' => 'jwt',
            'audience' => 'one-record',
            'leeway' => 0,
            'jwks_ttl' => 3600,
            'issuers' => [self::ISSUER => ['keys' => ['k1' => TestKeys::pair('host')['public']]]],
            'token_endpoint' => [
                'enabled' => true,
                'path' => '/oauth/token',
                'middleware' => ['throttle:60,1'],
                'issuer' => self::ISSUER,
                'private_key' => TestKeys::pair('host')['private'],
                'key_id' => 'k1',
                'ttl' => 600,
                'audience' => 'one-record',
            ],
            'jwks' => ['enabled' => true, 'path' => '/.well-known/jwks.json'],
        ]);
        $app->make(Repository::class)->set('hashing.bcrypt.rounds', 4);
    }

    private function credentials(): DatabaseClientCredentials
    {
        $credentials = $this->app()->make(ClientCredentialsVerifier::class);
        self::assertInstanceOf(DatabaseClientCredentials::class, $credentials);

        return $credentials;
    }

    public function testTheRoutesAreMountedWithTheConfiguredMiddleware(): void
    {
        $routes = $this->app()->make(Router::class)->getRoutes();
        $token = $routes->getByName('one-record.token');
        $jwks = $routes->getByName('one-record.jwks');

        self::assertNotNull($token);
        self::assertSame('oauth/token', $token->uri());
        self::assertSame(['POST'], $token->methods());
        self::assertSame(['throttle:60,1'], $token->gatherMiddleware());
        self::assertNotNull($jwks);
        self::assertSame('.well-known/jwks.json', $jwks->uri());
    }

    public function testARegisteredPartnerObtainsATokenTheServerAccepts(): void
    {
        $this->credentials()->create('partner-1', 's3cret', new Iri(self::PARTNER), 'Partner One');

        $response = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'partner-1', 'client_secret' => 's3cret'], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $token = $response->json('access_token');
        self::assertIsString($token);
        self::assertSame('Bearer', $response->json('token_type'));
        self::assertSame(600, $response->json('expires_in'));
        $this->serverInformation($token)->assertOk();

        $basic = $this->call('POST', '/oauth/token', ['grant_type' => 'client_credentials'], [], [], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('partner-1:s3cret')]);
        $basic->assertOk();

        $used = $this->app()->make(ConnectionInterface::class)->table($this->app()->make(Tables::class)->clients())->where('client_id', 'partner-1')->value('last_used_at');
        self::assertNotNull($used);
    }

    public function testWrongUnknownAndDisabledClientsAreRefusedAlike(): void
    {
        $this->credentials()->create('partner-1', 's3cret', new Iri(self::PARTNER));
        $this->credentials()->create('partner-2', 'other', new Iri(self::STRANGER));
        self::assertTrue($this->credentials()->setEnabled('partner-2', false));
        self::assertFalse($this->credentials()->setEnabled('nobody', false));

        foreach ([['partner-1', 'wrong'], ['nobody', 's3cret'], ['partner-2', 'other']] as [$id, $secret]) {
            $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret])
                ->assertStatus(401)
                ->assertJsonPath('error', 'invalid_client');
        }
        $this->post('/oauth/token', ['grant_type' => 'password', 'client_id' => 'partner-1', 'client_secret' => 's3cret'])->assertStatus(400);
        $this->get('/oauth/token')->assertStatus(405);
    }

    public function testTheJwksRoutePublishesTheSigningKey(): void
    {
        $response = $this->get('/.well-known/jwks.json');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
        $response->assertJsonPath('keys.0.kid', 'k1');
        $response->assertJsonPath('keys.0.kty', 'RSA');
        $response->assertJsonPath('keys.0.alg', 'RS256');
        self::assertIsString($response->json('keys.0.n'));
    }

    public function testTheCommandRegistersAClientAndShowsTheSecretOnce(): void
    {
        $command = $this->artisan('one-record:client:create', ['agent' => self::PARTNER, '--name' => 'Partner One', '--client-id' => 'cli-1']);
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('cli-1')->assertSuccessful()->run();

        $row = $this->app()->make(ConnectionInterface::class)->table($this->app()->make(Tables::class)->clients())->where('client_id', 'cli-1')->first();
        self::assertNotNull($row);
        self::assertSame('Partner One', ((array) $row)['name']);
        self::assertSame(self::PARTNER, ((array) $row)['agent_iri']);
        $hash = ((array) $row)['secret_hash'];
        self::assertIsString($hash);
        self::assertStringStartsWith('$2y$', $hash);

        $invalid = $this->artisan('one-record:client:create', ['agent' => 'not an iri']);
        self::assertInstanceOf(PendingCommand::class, $invalid);
        $invalid->assertFailed()->run();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function serverInformation(string $bearer): TestResponse
    {
        return $this->call('GET', self::BASE . '/one-record', [], [], [], ['HTTP_ACCEPT' => 'application/ld+json; version=2.3.0', 'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer]);
    }
}
