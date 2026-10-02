<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Change\ChangeBuilder;
use LambdaTwelve\OneRecord\Laravel\Http\TransactionalRequestHandler;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\HeaderAuthenticator;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\Event\ActionRequestCreated;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Services;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * A request the SDK answers with 5xx leaves nothing behind. The SDK saves
 * the action request before dispatching ActionRequestCreated, so a listener
 * that throws is exactly the partial-write case the transaction exists for.
 */
#[CoversClass(TransactionalRequestHandler::class)]
final class TransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function storageDriver(): string
    {
        return 'database';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app()->instance(Authenticator::class, new HeaderAuthenticator());
    }

    public function testAFailedRequestIsRolledBack(): void
    {
        $this->assertRowsAfterAFailingListener(transactions: true, expectedRequests: 0);
    }

    public function testWithoutTransactionsThePartialWriteStays(): void
    {
        $this->assertRowsAfterAFailingListener(transactions: false, expectedRequests: 1);
    }

    private function assertRowsAfterAFailingListener(bool $transactions, int $expectedRequests): void
    {
        $this->config()->set('one-record.storage.transactions', $transactions);
        $config = $this->app()->make(ServerConfig::class);
        $iri = $config->logisticsObjectIri('piece-1');
        $holder = $this->app()->make(DataHolder::class);
        $holder->create(ObjectBuilder::of(Cargo::Piece)->set(Cargo::goodsDescription, 'Perishables')->build($iri));
        $policy = $this->app()->make(AccessPolicy::class);
        self::assertInstanceOf(GrantAccessPolicy::class, $policy);
        $policy->allow(new Iri(self::PARTNER), $iri, [Permission::GetLogisticsObject, Permission::PatchLogisticsObject]);
        $this->app()->make(Dispatcher::class)->listen(ActionRequestCreated::class, static function (): void {
            throw new RuntimeException('listener failed');
        });

        $services = $this->app()->make(Services::class);
        $current = $services->objects->latest($iri);
        self::assertNotNull($current);
        $change = (new ChangeBuilder($services->vocabulary))->diff($current->object, ObjectBuilder::of(Cargo::Piece)->set(Cargo::goodsDescription, 'Frozen')->build($iri), 1);
        self::assertNotNull($change);

        $response = $this->call('PATCH', self::BASE . '/one-record/logistics-objects/piece-1', [], [], [], [
            'HTTP_ACCEPT' => 'application/ld+json; version=2.3.0',
            'CONTENT_TYPE' => 'application/ld+json; version=2.3.0',
            'HTTP_X_TEST_AGENT' => self::PARTNER,
        ], $change->toJson());

        $response->assertStatus(500);
        $tables = $this->app()->make(Tables::class);
        $db = $this->app()->make(ConnectionInterface::class);
        self::assertSame($expectedRequests, $db->table($tables->actionRequests())->count());
        self::assertSame($expectedRequests, $db->table($tables->actionRequestObjects())->count());
        self::assertSame(1, $db->table($tables->revisions())->count(), 'the object itself was committed before the request');
    }
}
