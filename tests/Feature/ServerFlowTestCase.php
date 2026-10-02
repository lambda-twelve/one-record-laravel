<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Testing\TestResponse;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Change\ChangeBuilder;
use LambdaTwelve\OneRecord\Laravel\Bridge\LaravelEventDispatcher;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\ServerController;
use LambdaTwelve\OneRecord\Laravel\Http\PsrBridge;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\HeaderAuthenticator;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Model\LogisticsObject;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\Event\ActionRequestCreated;
use LambdaTwelve\OneRecord\Server\Event\LogisticsObjectCreated;
use LambdaTwelve\OneRecord\Server\Event\LogisticsObjectRevised;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryAccessPolicy;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Services;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Laravel request -> SDK server -> Laravel response, end to end, once on the
 * SDK's in-memory stores and once on the database stores. Authentication is
 * a trusted test header; the JWT path has its own tests.
 */
#[CoversClass(ServerController::class)]
#[CoversClass(PsrBridge::class)]
#[CoversClass(LaravelEventDispatcher::class)]
#[CoversClass(OneRecordServiceProvider::class)]
abstract class ServerFlowTestCase extends TestCase
{
    protected const string JSON_LD = 'application/ld+json; version=2.3.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app()->instance(Authenticator::class, new HeaderAuthenticator());
    }

    public function testServerInformationIsServedUnderTheBasePath(): void
    {
        $response = $this->request('GET', '/one-record', self::PARTNER);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::JSON_LD);
        self::assertStringContainsString('ServerInformation', self::body($response));
        self::assertStringContainsString(self::HOLDER, self::body($response));
    }

    public function testRequestsWithoutAnAgentAreRefusedBySdkRules(): void
    {
        $response = $this->request('GET', '/one-record', null);

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', self::JSON_LD);
        self::assertStringContainsString('Error', self::body($response));
    }

    public function testNothingOutsideTheBasePathReachesTheSdk(): void
    {
        $this->request('GET', '/elsewhere', self::HOLDER)->assertNotFound();
    }

    public function testAPublishedObjectIsReadableAccordingToTheAccessPolicy(): void
    {
        $iri = $this->publishPiece('piece-1', 'Perishables');

        $holder = $this->request('GET', '/one-record/logistics-objects/piece-1', self::HOLDER);
        $holder->assertOk();
        $holder->assertHeader('Content-Type', self::JSON_LD);
        $holder->assertHeader('Revision', '1');
        $holder->assertHeader('Latest-Revision', '1');
        $holder->assertHeader('Type', Cargo::Piece);
        self::assertStringContainsString('Perishables', self::body($holder));
        self::assertStringContainsString($iri->value, self::body($holder));

        $this->request('GET', '/one-record/logistics-objects/piece-1', self::PARTNER)->assertForbidden();
        $this->request('GET', '/one-record/logistics-objects/missing', self::HOLDER)->assertNotFound();

        $this->policy()->allow(new Iri(self::PARTNER), $iri, [Permission::GetLogisticsObject]);
        $this->request('GET', '/one-record/logistics-objects/piece-1', self::PARTNER)->assertOk();
        $head = $this->request('HEAD', '/one-record/logistics-objects/piece-1', self::PARTNER);
        $head->assertOk();
        self::assertSame('', self::body($head));
    }

    public function testAPartnerChangeRequestGoesThroughTheActionRequestLifecycle(): void
    {
        $iri = $this->publishPiece('piece-2', 'Perishables');
        $this->policy()->allow(new Iri(self::PARTNER), $iri, [Permission::GetLogisticsObject, Permission::PatchLogisticsObject]);
        $events = [];
        $this->app()->make(Dispatcher::class)->listen([ActionRequestCreated::class, LogisticsObjectRevised::class], static function (object $event) use (&$events): void {
            $events[] = $event::class;
        });

        $services = $this->app()->make(Services::class);
        $current = $services->objects->latest($iri);
        self::assertNotNull($current);
        $change = (new ChangeBuilder($services->vocabulary))->diff($current->object, $this->piece('piece-2', 'Frozen fish'), 1, 'Correct the description');
        self::assertNotNull($change);

        $patch = $this->request('PATCH', '/one-record/logistics-objects/piece-2', self::PARTNER, $change->toJson());
        $patch->assertCreated();
        $location = $patch->headers->get('Location');
        self::assertIsString($location);
        self::assertStringStartsWith(self::BASE . '/one-record/action-requests/', $location);

        $pending = $this->request('GET', (string) parse_url($location, PHP_URL_PATH), self::PARTNER);
        $pending->assertOk();
        self::assertStringContainsString('REQUEST_PENDING', self::body($pending));
        $this->request('GET', '/one-record/logistics-objects/piece-2', self::PARTNER)->assertHeader('Latest-Revision', '1');

        $this->app()->make(DataHolder::class)->accept(new Iri($location));

        $revised = $this->request('GET', '/one-record/logistics-objects/piece-2', self::PARTNER);
        $revised->assertHeader('Latest-Revision', '2');
        self::assertStringContainsString('Frozen fish', self::body($revised));
        self::assertStringContainsString('REQUEST_ACCEPTED', self::body($this->request('GET', (string) parse_url($location, PHP_URL_PATH), self::PARTNER)));
        self::assertSame([ActionRequestCreated::class, LogisticsObjectRevised::class], $events);
    }

    public function testSdkEventsReachLaravelListeners(): void
    {
        $seen = null;
        $this->app()->make(Dispatcher::class)->listen(LogisticsObjectCreated::class, static function (LogisticsObjectCreated $event) use (&$seen): void {
            $seen = $event;
        });

        $iri = $this->publishPiece('piece-3', 'Flowers');

        self::assertInstanceOf(LogisticsObjectCreated::class, $seen);
        self::assertSame($iri->value, $seen->stored->object->iri->value);
        self::assertSame(self::HOLDER, $seen->createdBy?->value);
    }

    protected function publishPiece(string $id, string $description): Iri
    {
        $stored = $this->app()->make(DataHolder::class)->create($this->piece($id, $description));

        return $stored->object->iri;
    }

    protected function piece(string $id, string $description): LogisticsObject
    {
        return ObjectBuilder::of(Cargo::Piece)
            ->set(Cargo::goodsDescription, $description)
            ->build($this->app()->make(ServerConfig::class)->logisticsObjectIri($id));
    }

    protected function policy(): InMemoryAccessPolicy
    {
        $policy = $this->app()->make(AccessPolicy::class);
        self::assertInstanceOf(InMemoryAccessPolicy::class, $policy);

        return $policy;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected static function body(TestResponse $response): string
    {
        $content = $response->baseResponse->getContent();

        return $content === false ? '' : $content;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function request(string $method, string $path, ?string $agent, ?string $body = null): TestResponse
    {
        $server = ['HTTP_ACCEPT' => self::JSON_LD];
        if ($agent !== null) {
            $server['HTTP_X_TEST_AGENT'] = $agent;
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = self::JSON_LD;
        }

        return $this->call($method, self::BASE . $path, [], [], [], $server, $body);
    }
}
