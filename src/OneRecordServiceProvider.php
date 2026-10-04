<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LambdaTwelve\OneRecord\Auth\ClientCredentialsVerifier;
use LambdaTwelve\OneRecord\Auth\InMemoryClientCredentials;
use LambdaTwelve\OneRecord\Auth\Jwt\Rs256Signer;
use LambdaTwelve\OneRecord\Auth\TokenEndpoint;
use LambdaTwelve\OneRecord\Laravel\Auth\AuthenticatorFactory;
use LambdaTwelve\OneRecord\Laravel\Bridge\LaravelClock;
use LambdaTwelve\OneRecord\Laravel\Bridge\LaravelEventDispatcher;
use LambdaTwelve\OneRecord\Laravel\Config\ServerConfigFactory;
use LambdaTwelve\OneRecord\Laravel\Console\CreateClientCommand;
use LambdaTwelve\OneRecord\Laravel\Console\DeliverOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\Console\PruneOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\Console\RetryOutboxCommand;
use LambdaTwelve\OneRecord\Laravel\Http\Controllers\ServerController;
use LambdaTwelve\OneRecord\Laravel\Http\TransactionalRequestHandler;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliverNotification;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseAccessDelegationStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseClientCredentials;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsEventStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsObjectStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseUnitOfWork;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;
use LambdaTwelve\OneRecord\Model\IriMinter;
use LambdaTwelve\OneRecord\Model\UuidIriMinter;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ActionRequests;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Server\IdentityUnitOfWork;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryState;
use LambdaTwelve\OneRecord\Server\OneRecordServer;
use LambdaTwelve\OneRecord\Server\ServerBuilder;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Services;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use LambdaTwelve\OneRecord\Server\Spi\Decision;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Server\Spi\UnitOfWork;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wires lambda-twelve/one-record into Laravel's container, routes and console.
 * Everything protocol-related stays in the SDK; this provider only translates
 * Laravel configuration and services into the SDK's constructor arguments.
 *
 * Container keys the package owns besides the SDK classes and SPI interfaces:
 * `one-record.logger` (the PSR-3 channel the SDK logs to) and
 * `one-record.cache` (the PSR-16 store the SDK caches in).
 */
final class OneRecordServiceProvider extends ServiceProvider
{
    public const string LOGGER = 'one-record.logger';
    public const string CACHE = 'one-record.cache';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/one-record.php', 'one-record');
        $this->registerFrameworkServices();
        $this->registerStores();
        $this->registerServer();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/one-record.php' => $this->app->configPath('one-record.php')], 'one-record-config');
            $this->publishes([__DIR__ . '/../database/migrations' => $this->app->databasePath('migrations')], 'one-record-migrations');
        }
        if ((bool) $this->config('storage.migrations', true) && is_dir(__DIR__ . '/../database/migrations')) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
        if ((bool) $this->config('routes.register', true) && !($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            OneRecord::routes();
            OneRecord::tokenRoutes();
        }
        if ($this->app->runningInConsole()) {
            $this->commands([CreateClientCommand::class, DeliverOutboxCommand::class, PruneOutboxCommand::class, RetryOutboxCommand::class]);
        }
    }

    /**
     * The PSR services the SDK consumes, bound only when the application has
     * not bound them itself. Logger and cache are Laravel-managed, so they get
     * package-private keys rather than overriding the PSR interfaces app-wide.
     */
    private function registerFrameworkServices(): void
    {
        $this->app->bindIf(ClockInterface::class, LaravelClock::class, true);
        $this->app->bindIf(EventDispatcherInterface::class, LaravelEventDispatcher::class, true);
        $this->app->bindIf(HttpFactory::class, HttpFactory::class, true);
        foreach ([RequestFactoryInterface::class, ResponseFactoryInterface::class, ServerRequestFactoryInterface::class, StreamFactoryInterface::class, UploadedFileFactoryInterface::class, UriFactoryInterface::class] as $factory) {
            $this->app->bindIf($factory, static fn(Container $app): HttpFactory => $app->make(HttpFactory::class), true);
        }
        $this->app->bindIf(ClientInterface::class, fn(): Client => new Client($this->arrayConfig('http')), true);
        $this->app->singleton(self::LOGGER, function (Container $app): LoggerInterface {
            $channel = $this->config('log.channel');

            return $app->make(LogManager::class)->channel(\is_string($channel) && $channel !== '' ? $channel : null);
        });
        $this->app->singleton(self::CACHE, function (Container $app): CacheInterface {
            $store = $this->config('cache.store');

            return $app->make(CacheFactory::class)->store(\is_string($store) && $store !== '' ? $store : null);
        });
    }

    /**
     * The SPI implementations, chosen by `one-record.storage.driver`.
     */
    private function registerStores(): void
    {
        $this->app->singleton(Tables::class, function (): Tables {
            $prefix = $this->config('storage.table_prefix', 'one_record_');

            return new Tables(\is_string($prefix) ? $prefix : 'one_record_');
        });
        $this->app->singleton(InMemoryState::class, fn(Container $app): InMemoryState => new InMemoryState($app->make(ClockInterface::class), $this->denial()));

        $this->app->singleton(LogisticsObjectStore::class, fn(Container $app): LogisticsObjectStore => $this->store(
            $app,
            static fn(InMemoryState $s): LogisticsObjectStore => $s->objects,
            static fn(ConnectionInterface $db, Tables $t): LogisticsObjectStore => new DatabaseLogisticsObjectStore($db, $t),
        ));
        $this->app->singleton(LogisticsEventStore::class, fn(Container $app): LogisticsEventStore => $this->store(
            $app,
            static fn(InMemoryState $s): LogisticsEventStore => $s->events,
            static fn(ConnectionInterface $db, Tables $t): LogisticsEventStore => new DatabaseLogisticsEventStore($db, $t),
        ));
        $this->app->singleton(ActionRequestStore::class, fn(Container $app): ActionRequestStore => $this->store(
            $app,
            static fn(InMemoryState $s): ActionRequestStore => $s->actionRequests,
            static fn(ConnectionInterface $db, Tables $t): ActionRequestStore => new DatabaseActionRequestStore($db, $t),
        ));
        $this->app->singleton(SubscriptionStore::class, fn(Container $app): SubscriptionStore => $this->store(
            $app,
            static fn(InMemoryState $s): SubscriptionStore => $s->subscriptions,
            static fn(ConnectionInterface $db, Tables $t): SubscriptionStore => new DatabaseSubscriptionStore($db, $t),
        ));
        $this->app->singleton(AccessDelegationStore::class, fn(Container $app): AccessDelegationStore => $this->store(
            $app,
            static fn(InMemoryState $s): AccessDelegationStore => $s->delegations,
            static fn(ConnectionInterface $db, Tables $t): AccessDelegationStore => new DatabaseAccessDelegationStore($db, $t),
        ));
        $this->app->singleton(NotificationOutbox::class, fn(Container $app): NotificationOutbox => $this->store(
            $app,
            static fn(InMemoryState $s): NotificationOutbox => $s->outbox,
            fn(ConnectionInterface $db, Tables $t): NotificationOutbox => new DatabaseNotificationOutbox($db, $t, $this->config('outbox.dispatch', 'queue') === 'queue'
                // Called by the outbox once the enqueuing transaction has committed, so the job never races the row.
                ? static function (int $id) use ($app): void {
                    DeliverNotification::dispatchFor($app, $id);
                }
                : null),
        ));
        // The SDK runs every mutating request and every DataHolder / ActionRequests operation through
        // this, so with the database driver each is one transaction (nested calls are savepoints).
        $this->app->singleton(UnitOfWork::class, fn(Container $app): UnitOfWork => $this->driver() === 'database'
            ? new DatabaseUnitOfWork($this->connection($app))
            : new IdentityUnitOfWork());

        $this->app->singleton(AccessPolicy::class, function (Container $app): AccessPolicy {
            // The SDK's grant-based policy works over any AccessDelegationStore; only the
            // internal-agent set lives in memory, and that comes from configuration.
            $policy = $this->driver() === 'array'
                ? $app->make(InMemoryState::class)->policy
                : new GrantAccessPolicy($app->make(AccessDelegationStore::class), $app->make(ClockInterface::class), $this->denial());
            $policy->addInternal($app->make(ServerConfig::class)->dataHolder);
            foreach ($this->arrayConfig('policy.internal_agents') as $agent) {
                if (\is_string($agent) && $agent !== '') {
                    $policy->addInternal(new Iri($agent));
                }
            }

            return $policy;
        });
    }

    private function registerServer(): void
    {
        $this->app->singleton(ServerConfig::class, fn(): ServerConfig => ServerConfigFactory::fromArray($this->arrayConfig('server')));
        $this->app->singleton(IriMinter::class, function (Container $app): IriMinter {
            $seed = $this->config('iri.seed');

            return new UuidIriMinter($app->make(ServerConfig::class)->endpoint(), \is_string($seed) && $seed !== '' ? $seed : null);
        });

        $this->app->singleton(AuthenticatorFactory::class, static fn(Container $app): AuthenticatorFactory => new AuthenticatorFactory(
            $app->make(ClockInterface::class),
            $app->make(ClientInterface::class),
            $app->make(RequestFactoryInterface::class),
            static fn(): CacheInterface => self::cache($app),
            self::logger($app),
        ));
        $this->app->singleton(Authenticator::class, function (Container $app): Authenticator {
            $driver = $this->config('auth.driver', 'jwt');

            return match ($driver) {
                'jwt' => $app->make(AuthenticatorFactory::class)->make($this->arrayConfig('auth')),
                'custom' => throw new LogicException('one-record.auth.driver is "custom": bind ' . Authenticator::class . ' in one of your service providers.'),
                default => throw new InvalidArgumentException(\sprintf('Unknown one-record.auth.driver "%s"; use jwt or custom.', \is_scalar($driver) ? (string) $driver : \gettype($driver))),
            };
        });

        $this->registerTokenIssuing();

        $this->app->singleton(Services::class, static fn(Container $app): Services => new Services(
            config: $app->make(ServerConfig::class),
            objects: $app->make(LogisticsObjectStore::class),
            events: $app->make(LogisticsEventStore::class),
            actionRequests: $app->make(ActionRequestStore::class),
            subscriptions: $app->make(SubscriptionStore::class),
            delegations: $app->make(AccessDelegationStore::class),
            outbox: $app->make(NotificationOutbox::class),
            authenticator: $app->make(Authenticator::class),
            policy: $app->make(AccessPolicy::class),
            clock: $app->make(ClockInterface::class),
            dispatcher: $app->make(EventDispatcherInterface::class),
            responses: $app->make(ResponseFactoryInterface::class),
            streams: $app->make(StreamFactoryInterface::class),
            logger: self::logger($app),
            unitOfWork: $app->make(UnitOfWork::class),
        ));
        $this->app->singleton(OneRecordServer::class, static fn(Container $app): OneRecordServer => ServerBuilder::build($app->make(Services::class)));
        $this->app->singleton(DataHolder::class, static fn(Container $app): DataHolder => new DataHolder($app->make(Services::class)));
        $this->app->singleton(ActionRequests::class, static fn(Container $app): ActionRequests => new ActionRequests($app->make(Services::class)));

        $this->app->when(ServerController::class)
            ->needs(RequestHandlerInterface::class)
            ->give(function (Container $app): RequestHandlerInterface {
                $server = $app->make(OneRecordServer::class);

                return $this->driver() === 'database' && (bool) $this->config('storage.transactions', true)
                    ? new TransactionalRequestHandler($server, $this->connection($app))
                    : $server;
            });
    }

    /**
     * The token endpoint this host offers its own partners: the SDK's signer
     * and endpoint over credentials stored (hashed) in the database.
     */
    private function registerTokenIssuing(): void
    {
        $this->app->singleton(Rs256Signer::class, function (Container $app): Rs256Signer {
            $settings = $this->arrayConfig('auth.token_endpoint');
            $key = $settings['private_key'] ?? null;
            if (!\is_string($key) || trim($key) === '') {
                throw new LogicException('Issuing tokens needs an RSA private key in one-record.auth.token_endpoint.private_key (ONE_RECORD_PRIVATE_KEY).');
            }
            $issuer = $settings['issuer'] ?? null;
            $keyId = $settings['key_id'] ?? null;

            return new Rs256Signer(
                $key,
                \is_string($issuer) && $issuer !== '' ? $issuer : $app->make(ServerConfig::class)->baseUrl,
                $app->make(ClockInterface::class),
                \is_string($keyId) && $keyId !== '' ? $keyId : null,
            );
        });
        $this->app->singleton(ClientCredentialsVerifier::class, fn(Container $app): ClientCredentialsVerifier => $this->driver() === 'database'
            ? new DatabaseClientCredentials($this->connection($app), $app->make(Tables::class), $app->make(Hasher::class), $app->make(ClockInterface::class), self::cache($app))
            : new InMemoryClientCredentials());
        $this->app->singleton(TokenEndpoint::class, function (Container $app): TokenEndpoint {
            $settings = $this->arrayConfig('auth.token_endpoint');
            $ttl = $settings['ttl'] ?? 3600;
            $audience = $settings['audience'] ?? null;

            return new TokenEndpoint(
                $app->make(ClientCredentialsVerifier::class),
                $app->make(Rs256Signer::class),
                $app->make(ResponseFactoryInterface::class),
                $app->make(StreamFactoryInterface::class),
                is_numeric($ttl) ? (int) $ttl : 3600,
                \is_string($audience) && $audience !== '' ? $audience : null,
                self::logger($app),
            );
        });
    }

    /**
     * @template T of object
     * @param callable(InMemoryState): T $fromState
     * @param callable(ConnectionInterface, Tables): T $fromDatabase
     * @return T
     */
    private function store(Container $app, callable $fromState, callable $fromDatabase): object
    {
        return match ($this->driver()) {
            'array' => $fromState($app->make(InMemoryState::class)),
            'database' => $fromDatabase($this->connection($app), $app->make(Tables::class)),
            default => throw new InvalidArgumentException(\sprintf('Unknown one-record.storage.driver "%s"; use database or array.', $this->driver())),
        };
    }

    public static function logger(Container $app): LoggerInterface
    {
        $logger = $app->make(self::LOGGER);
        if (!$logger instanceof LoggerInterface) {
            throw new LogicException(self::LOGGER . ' must resolve to a PSR-3 logger.');
        }

        return $logger;
    }

    public static function cache(Container $app): CacheInterface
    {
        $cache = $app->make(self::CACHE);
        if (!$cache instanceof CacheInterface) {
            throw new LogicException(self::CACHE . ' must resolve to a PSR-16 cache.');
        }

        return $cache;
    }

    private function connection(Container $app): ConnectionInterface
    {
        $name = $this->config('storage.connection');

        return $app->make(ConnectionResolverInterface::class)->connection(\is_string($name) && $name !== '' ? $name : null);
    }

    private function driver(): string
    {
        $driver = $this->config('storage.driver', 'database');

        return \is_string($driver) ? $driver : 'database';
    }

    private function denial(): Decision
    {
        $denial = $this->config('policy.denial', 'forbid');

        return match ($denial) {
            'hide' => Decision::Hide,
            'forbid', null, '' => Decision::Forbid,
            default => throw new InvalidArgumentException(\sprintf('Unknown one-record.policy.denial "%s"; use forbid or hide.', \is_scalar($denial) ? (string) $denial : \gettype($denial))),
        };
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make(Repository::class)->get('one-record.' . $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayConfig(string $key): array
    {
        $value = $this->config($key, []);

        /** @var array<string, mixed> */
        return \is_array($value) ? $value : [];
    }
}
