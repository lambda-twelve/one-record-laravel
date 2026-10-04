<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Feature;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Artisan;
use LambdaTwelve\OneRecord\Auth\JwtAuthenticator;
use LambdaTwelve\OneRecord\Laravel\Bridge\LaravelClock;
use LambdaTwelve\OneRecord\Laravel\Bridge\LaravelEventDispatcher;
use LambdaTwelve\OneRecord\Laravel\OneRecordServiceProvider;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseUnitOfWork;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\TestLogger;
use LambdaTwelve\OneRecord\Laravel\Tests\TestCase;
use LambdaTwelve\OneRecord\Model\IriMinter;
use LambdaTwelve\OneRecord\Model\UuidIriMinter;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ActionRequests;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryLogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryNotificationOutbox;
use LambdaTwelve\OneRecord\Server\OneRecordServer;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Services;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\Action;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\Agent;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use LambdaTwelve\OneRecord\Server\Spi\Decision;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Server\Spi\UnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(OneRecordServiceProvider::class)]
final class ServiceProviderTest extends TestCase
{
    public function testTheServerConfigComesFromTheLaravelConfiguration(): void
    {
        $config = $this->app()->make(ServerConfig::class);

        self::assertSame(self::BASE, $config->baseUrl);
        self::assertSame(self::BASE_PATH, $config->basePath);
        self::assertSame(self::HOLDER, $config->dataHolder->value);
        self::assertSame($config, $this->app()->make(ServerConfig::class), 'singleton');
    }

    public function testTheSdkWiringResolvesFromTheContainer(): void
    {
        $services = $this->app()->make(Services::class);

        self::assertInstanceOf(OneRecordServer::class, $this->app()->make(OneRecordServer::class));
        self::assertSame($services, $this->app()->make(DataHolder::class)->actionRequests() instanceof ActionRequests ? $services : null);
        self::assertInstanceOf(ActionRequests::class, $this->app()->make(ActionRequests::class));
        self::assertInstanceOf(UuidIriMinter::class, $this->app()->make(IriMinter::class));
        self::assertSame($services->objects, $this->app()->make(LogisticsObjectStore::class));
    }

    public function testTheArrayDriverUsesTheSdkInMemoryStores(): void
    {
        self::assertInstanceOf(InMemoryLogisticsObjectStore::class, $this->app()->make(LogisticsObjectStore::class));
        self::assertInstanceOf(InMemoryNotificationOutbox::class, $this->app()->make(NotificationOutbox::class));
        foreach ([LogisticsEventStore::class, ActionRequestStore::class, SubscriptionStore::class, AccessDelegationStore::class] as $spi) {
            self::assertInstanceOf($spi, $this->app()->make($spi));
        }
    }

    /**
     * Since beta3 the SDK warns when persistent stores come without a unit of
     * work; with the database driver the provider binds one, so it does not.
     */
    public function testTheDatabaseDriverBindsTheUnitOfWorkSoTheSdkDoesNotWarn(): void
    {
        $this->config()->set('one-record.storage.driver', 'database');
        $logger = new TestLogger();
        $this->app()->instance(OneRecordServiceProvider::LOGGER, $logger);

        $services = $this->app()->make(Services::class);

        self::assertInstanceOf(DatabaseUnitOfWork::class, $this->app()->make(UnitOfWork::class));
        self::assertSame($this->app()->make(UnitOfWork::class), $services->unitOfWork);
        self::assertSame([], $logger->records, 'no "operations will not be atomic" warning');
    }

    public function testTheAboutCommandShowsTheSdkWiringFindings(): void
    {
        // Artisan::output() rather than PendingCommand's expectations: the two-column lines of
        // `about` are padded to the terminal width, which the command mock does not reproduce.
        self::assertSame(0, Artisan::call('about', ['--only' => 'one_record']));
        $output = Artisan::output();

        self::assertStringContainsString('ONE Record', $output);
        self::assertMatchesRegularExpression('/SDK \.+ 1\.0\.0-beta\d+/', $output);
        self::assertMatchesRegularExpression('/Storage driver \.+ array/', $output);
        self::assertMatchesRegularExpression('/Configuration \.+ OK/', $output);
        self::assertMatchesRegularExpression('/Wiring \.+ OK/', $output, 'the unit of work is bound, so the SDK reports nothing');
    }

    public function testFrameworkServicesAreBoundForTheSdk(): void
    {
        self::assertInstanceOf(LaravelClock::class, $this->app()->make(ClockInterface::class));
        self::assertInstanceOf(LaravelEventDispatcher::class, $this->app()->make(EventDispatcherInterface::class));
        self::assertInstanceOf(HttpFactory::class, $this->app()->make(ResponseFactoryInterface::class));
        self::assertInstanceOf(HttpFactory::class, $this->app()->make(StreamFactoryInterface::class));
        self::assertInstanceOf(HttpFactory::class, $this->app()->make(RequestFactoryInterface::class));
        self::assertInstanceOf(Client::class, $this->app()->make(ClientInterface::class));
        self::assertInstanceOf(LoggerInterface::class, $this->app()->make(OneRecordServiceProvider::LOGGER));
        $cache = $this->app()->make(OneRecordServiceProvider::CACHE);
        self::assertInstanceOf(CacheInterface::class, $cache);
        self::assertInstanceOf(CacheRepository::class, $cache);
    }

    public function testApplicationBindingsWin(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2020-01-01T00:00:00Z');
            }
        };
        $this->app()->instance(ClockInterface::class, $clock);

        self::assertSame($clock, $this->app()->make(Services::class)->clock);
    }

    public function testThePolicyTreatsTheDataHolderAndConfiguredAgentsAsInternal(): void
    {
        $this->config()->set('one-record.policy.internal_agents', ['https://erp.example/agent']);
        $policy = $this->app()->make(AccessPolicy::class);

        self::assertInstanceOf(GrantAccessPolicy::class, $policy);
        self::assertSame(Decision::Allow, $policy->decide(new Agent(new Iri(self::HOLDER)), Action::CreateLogisticsObject, null));
        self::assertSame(Decision::Allow, $policy->decide(new Agent(new Iri('https://erp.example/agent')), Action::DecideActionRequest, new Iri(self::BASE . '/one-record/action-requests/x')));
        self::assertSame(Decision::Forbid, $policy->decide(new Agent(new Iri(self::STRANGER)), Action::ReadLogisticsObject, new Iri(self::BASE . '/one-record/logistics-objects/x')));
    }

    public function testHideDenialIsConfigurable(): void
    {
        $this->config()->set('one-record.policy.denial', 'hide');

        self::assertSame(Decision::Hide, $this->app()->make(AccessPolicy::class)->decide(new Agent(new Iri(self::STRANGER)), Action::ReadLogisticsObject, new Iri(self::BASE . '/one-record/logistics-objects/x')));
    }

    public function testTheDefaultAuthenticatorIsTheSdkJwtAuthenticatorAndRefusesEverythingWithoutIssuers(): void
    {
        $authenticator = $this->app()->make(Authenticator::class);

        self::assertInstanceOf(JwtAuthenticator::class, $authenticator);
        self::assertNull($authenticator->authenticate(new ServerRequest('GET', self::BASE . '/one-record')));
        self::assertNull($authenticator->authenticate(new ServerRequest('GET', self::BASE . '/one-record', ['Authorization' => 'Bearer not.a.token'])));
    }

    public function testACustomAuthDriverMustBeBoundByTheApplication(): void
    {
        $this->config()->set('one-record.auth.driver', 'custom');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('bind ' . Authenticator::class);
        $this->app()->make(Authenticator::class);
    }
}
