# lambda-twelve/one-record-laravel

Laravel integration for [`lambda-twelve/one-record`](https://github.com/lambda-twelve/one-record),
the framework-agnostic PHP implementation of **IATA ONE Record**.

> **Status:** pre-release, `1.0.0-beta1`, following the SDK's `1.0.0-beta3`.
> The public API may still change between betas; `CHANGELOG.md` records it.

## What this package is

The SDK implements the ONE Record protocol: the PSR-15 server with every
endpoint, the action-request lifecycle, JSON-LD, the data model, RS256 tokens
and (soon) the client. It has a hard rule: nothing in it knows about a
framework or a database. Everything a host must provide is an interface, with
in-memory reference implementations.

This package is the Laravel host. It makes the SDK a natural Laravel citizen
without reimplementing any of it:

```text
Laravel application
      ↓  type-hints SDK interfaces, listens for SDK events, calls DataHolder
lambda-twelve/one-record-laravel   provider, config, routes, database stores, auth wiring, outbox job
      ↓  Services + ServerBuilder, SPI implementations, PSR bindings
lambda-twelve/one-record           protocol, JSON-LD, model, server, client
      ↓
PHP + PSR interfaces
```

Protocol and domain logic live in the SDK; framework glue lives here; business
logic (what a shipment is, which partner may see what) lives in your
application.

## Requirements

| | Supported | Why |
| --- | --- | --- |
| PHP | 8.3, 8.4, 8.5 | The SDK requires 8.3; Laravel 13 requires 8.3; these are the maintained PHP branches. |
| Laravel | 12 and 13 | Laravel 11 reached end of life in March 2026. 12 receives security fixes until February 2027; 13 is current. |
| Databases | SQLite, MariaDB/MySQL, PostgreSQL | The store tests run on all three in CI. |

Runtime dependencies beyond the SDK and Laravel's own components are PSR
interfaces and Guzzle, which Laravel already ships. There is no extra PSR-7
bridge package: the request/response conversion is written here on Guzzle's
PSR-7.

## Installation

The SDK and this package are pre-releases on Packagist, and Composer only
honours a stability flag written in the root `composer.json`, so allow it for
these two packages rather than lowering your application's minimum stability:

```sh
composer require "lambda-twelve/one-record:^1.0.0-beta3" "lambda-twelve/one-record-laravel:^1.0@beta"
```

The service provider is auto-discovered. Publish the configuration and, if you
want to adapt them, the migrations:

```sh
php artisan vendor:publish --tag=one-record-config
php artisan vendor:publish --tag=one-record-migrations   # optional; they load from the package otherwise
php artisan migrate
```

## Configuration

`config/one-record.php`. Every key maps onto an SDK constructor argument; the
package keeps no configuration model of its own.

| Key | Environment | Meaning |
| --- | --- | --- |
| `server.base_url` | `ONE_RECORD_BASE_URL` (falls back to `APP_URL`) | Scheme and host only, e.g. `https://1r.example.com`. A URL with a path is refused: put the prefix in `base_path`. |
| `server.base_path` | `ONE_RECORD_BASE_PATH` | Where the endpoints are mounted, default `/one-record`. It is also the route prefix, so the two cannot diverge. |
| `server.data_holder` | `ONE_RECORD_DATA_HOLDER` | The IRI of the organisation holding the data, or a bare id placed under `{base_url}{base_path}/logistics-objects/`. |
| `server.data_holder_type` | `ONE_RECORD_DATA_HOLDER_TYPE` | Class IRI of the holder, default `cargo:Company`. |
| `server.api_versions`, `server.data_model_versions` | `ONE_RECORD_API_VERSIONS`, `ONE_RECORD_DATA_MODEL_VERSIONS` | `null` for everything the SDK knows, or `2.3.0` / `2.2.0,2.3.0`. |
| `server.languages`, `max_body_bytes`, `embedded_depth`, `bulk_logistics_events` | | Passed straight to the SDK's `ServerConfig`. |
| `routes.register`, `middleware`, `domain`, `name` | | Automatic route registration, or leave it to your routes file (below). |
| `storage.driver` | `ONE_RECORD_STORAGE` | `database` (default) or `array` (the SDK's in-memory stores, one set per process; tests only). |
| `storage.connection`, `table_prefix`, `migrations`, `transactions` | `ONE_RECORD_DB_CONNECTION` | Which connection, the `one_record_` prefix, whether the package loads its migrations, and whether each whole request runs in one transaction on top of the SDK's unit of work. |
| `policy.denial`, `policy.internal_agents` | `ONE_RECORD_DENIAL` | `forbid` (403) or `hide` (404) for refused partners; agents allowed to do everything. The data holder is always internal. |
| `auth.*` | `ONE_RECORD_AUTH`, `ONE_RECORD_AUDIENCE`, `ONE_RECORD_ISSUER`, `ONE_RECORD_PRIVATE_KEY`, `ONE_RECORD_KEY_ID`, `ONE_RECORD_TOKEN_ENDPOINT`, `ONE_RECORD_JWKS` | Partner authentication and this host's token endpoint (below). |
| `cache.store`, `log.channel`, `http` | `ONE_RECORD_CACHE_STORE`, `ONE_RECORD_LOG_CHANNEL` | The cache store the SDK caches in (JWKS documents, tokens), the log channel it logs to, Guzzle options. |
| `outbox.*` | `ONE_RECORD_OUTBOX_DISPATCH`, `ONE_RECORD_OUTBOX_QUEUE_CONNECTION`, `ONE_RECORD_OUTBOX_QUEUE` | Notification delivery (below). |
| `iri.seed` | `ONE_RECORD_IRI_SEED` | Deterministic UUIDv5 IRIs when publishing local graphs; random UUIDv4 otherwise. |

All IRIs the server mints embed `base_url` and `base_path` and are stored
inside the published graphs: changing either later orphans stored data.

## Serving ONE Record: the quick start

Point `ONE_RECORD_DATA_HOLDER` at your organisation, trust at least one
partner issuer (see Authentication), migrate, and the server answers under
`/one-record`:

```sh
curl -H 'Accept: application/ld+json; version=2.3.0' \
     -H "Authorization: Bearer $TOKEN" \
     https://1r.example.com/one-record
```

Publishing your own data goes through the SDK's `DataHolder`, which the
container provides:

```php
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

$config = app(ServerConfig::class);
$holder = app(DataHolder::class);

$piece = ObjectBuilder::of(Cargo::Piece)
    ->set(Cargo::goodsDescription, 'Perishables')
    ->build($config->logisticsObjectIri('piece-1'));

$holder->create($piece);

// Let a partner read it: the default policy is the SDK's grant-based one, and
// with the database driver its grants live in one_record_grants.
$policy = app(AccessPolicy::class);
if ($policy instanceof GrantAccessPolicy) {
    $policy->allow(new Iri('https://partner.example/logistics-objects/partner'), $piece->iri, [Permission::GetLogisticsObject]);
}
```

`DataHolder::publish()` resolves a whole local graph, `update()` diffs and
applies a change, `accept()` / `reject()` decide partners' change requests;
see the SDK's documentation for the PHP API. Every such operation runs in the
SDK's unit of work, which this package binds to the storage connection
(`DatabaseUnitOfWork`): the rows it writes stand or fall together, and a
listener that throws rolls them back, with no `DB::transaction()` of your own.
To commit several operations together, wrap them in one; the nested units
become savepoints.

## Routes

With `routes.register` true (the default) the provider mounts every endpoint
under `base_path`. To control grouping yourself, set it to false and register
from your routes file:

```php
use LambdaTwelve\OneRecord\Laravel\OneRecord;

OneRecord::routes(['middleware' => ['api'], 'domain' => '1r.example.com', 'name' => 'one-record.']);
OneRecord::tokenRoutes();   // the token endpoint and JWKS document, when enabled
```

The prefix is never an option: the SDK strips `base_path` from every request
itself. The route is `Route::any('{path?}')` inside the group, so an empty
base path would catch your whole application; use a prefix.

Every request is converted to PSR-7, handled by the SDK's `OneRecordServer`,
and converted back. With the database driver, every mutating request runs in
the SDK's unit of work on the storage connection, so what one operation
writes (a revision, its action request, grants, outbox rows) is committed as a
whole or not at all, and is unwound before an error becomes a response: an
acceptance that loses the status race to a competing decision answers 409 with
its grants already rolled back. With `storage.transactions` on (the default)
the whole request is additionally one transaction, rolled back when the SDK
answers 5xx; 4xx responses commit on purpose (a change that failed to apply is
a stored, failed action request). Listeners of SDK events run inside the unit
of work: a listener that throws turns the request into a 500 and rolls it
back; listeners that do I/O should be queued and dispatched after commit.

## Storage

The `database` driver persists the SDK's value objects as the JSON-LD the SDK
writes, plus the columns the SDK's queries filter on. There are no Eloquent
models; the domain model stays the SDK's. Tables, all prefixed `one_record_`:

| Table | Holds |
| --- | --- |
| `logistics_objects`, `logistics_object_revisions` | One head row per object (latest revision, creation time) and one row per revision. New revisions are an atomic compare-and-set on the head row, so concurrent writers cannot both win. |
| `logistics_events` | Append-only events with `event_code`, `event_date`, `creation_date` for the spec's filters. |
| `action_requests`, `action_request_objects` | Change, verification, subscription and access-delegation requests at the newest API version, and the objects each one concerns. |
| `subscription_offers` | Subscriptions this host offers when asked `GET /subscriptions` (`DatabaseSubscriptionStore::offer()`). |
| `grants` | Permissions per agent and object, from the holder or from accepted delegations. |
| `outbox` | Outgoing notifications until delivered. |
| `clients` | Partners that may obtain tokens from this host. |

IRIs that are filtered or joined on carry a SHA-256 sibling column with the
index, so matching is byte-exact on every collation. Timestamps are UTC with
microsecond columns; the package clock truncates to milliseconds because that
is the precision of the SDK's JSON-LD literals.

Known differences from the SDK's in-memory stores, by design: appending an
event IRI twice fails (events are immutable), and an object's `Last-Modified`
for events is the newest event rather than the last appended one.

## Authentication

Partners authenticate with RS256 bearer tokens, verified by the SDK's
`JwtAuthenticator`. You configure whom to trust:

```php
'auth' => [
    'driver' => 'jwt',
    'audience' => env('ONE_RECORD_AUDIENCE'),       // optional aud check
    'issuers' => [
        'https://auth.partner.example' => ['keys' => [$pem]],               // static PEM(s), or ['kid' => $pem]
        'https://auth.other.example'   => ['jwks' => true],                 // {issuer}/.well-known/jwks.json
        'https://auth.third.example'   => ['jwks' => 'https://.../jwks'],   // explicit JWKS URL
    ],
],
```

JWKS documents are fetched with the PSR-18 client and cached in the configured
cache store. With no issuers configured every token is refused. Set
`auth.driver` to `custom` and bind `LambdaTwelve\OneRecord\Server\Spi\Authenticator`
to use your own authentication.

Authorisation is the SDK's grant-based policy over the `grants` table, with
`policy.internal_agents` (and always the data holder) allowed to do everything.
Bind `LambdaTwelve\OneRecord\Server\Spi\AccessPolicy` to replace it with your
own rules ("a partner sees only the shipments routed to it").

### Issuing tokens to your partners

Enable the SDK's client-credentials token endpoint and publish its key:

```dotenv
ONE_RECORD_TOKEN_ENDPOINT=true
ONE_RECORD_JWKS=true
ONE_RECORD_ISSUER=https://1r.example.com
ONE_RECORD_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
ONE_RECORD_KEY_ID=2026-10
```

Register a partner; the secret is shown once and only its hash is stored:

```sh
php artisan one-record:client:create https://partner.example/logistics-objects/partner --name="Partner One"
```

The partner posts `grant_type=client_credentials` with its id and secret to
`/oauth/token` (form fields or HTTP Basic) and receives a token whose
`logistics_agent_uri` claim is the IRI you registered. To accept those tokens
on your own server, list your issuer in `auth.issuers` with the matching public
key (or `['jwks' => true]`). The token route carries `throttle:60,1` by
default; adjust `auth.token_endpoint.middleware`.

## Events

The SDK raises PSR-14 events; the package bridges them to Laravel's
dispatcher, so you listen for the SDK's classes directly:

```php
use LambdaTwelve\OneRecord\Server\Event\LogisticsEventReceived;

Event::listen(LogisticsEventReceived::class, function (LogisticsEventReceived $event) {
    // $event->event is the SDK's LogisticsEvent, $event->postedBy the partner
});
```

Available: `LogisticsObjectCreated`, `LogisticsObjectRevised`,
`LogisticsEventReceived`, `ActionRequestCreated`, `ActionRequestStatusChanged`,
`NotificationReceived` (all under `LambdaTwelve\OneRecord\Server\Event`), plus
this package's `NotificationDelivered` and `NotificationDeliveryFailed`.

## Notifications: the outbox

The SDK enqueues outgoing notifications; delivering them is the host's job.
Every fan-out writes a row to `one_record_outbox` and, with
`outbox.dispatch=queue`, dispatches a `DeliverNotification` job after the
transaction commits. The job leases the row for `outbox.lease_seconds`, hands
it to your `NotificationDeliverer`, and records the outcome under that lease:
delivered, retry with a growing backoff (a minute, five, fifteen, an hour,
four, twelve, a day), or given up after `outbox.max_attempts`. A worker whose
lease expired while it was still delivering cannot overwrite what the worker
that took the row over records.

Delivery is **at least once**: a lease that expires mid-delivery lets another
worker deliver the same notification again, so recipients deduplicate on the
notification id (`$pending->outbound->id`, which your deliverer should send as
the `Idempotency-Key` header). The job is unique per row until a worker starts
it, so sweeps while workers lag do not pile up jobs; the row's lease, not the
queue, decides who delivers.

One delay to know about with the `database` queue driver: the job is queued
as soon as the notification's own transaction commits, but if your queue
table lives on a connection that has a transaction of its own open at that
moment, the job row is written inside that transaction and a later rollback
takes it with it. The notification stays in the outbox, and the lock the lost
job left keeps sweeps from queuing it again for up to an hour; the first sweep
after that does. Nothing is lost, delivery is delayed by up to that hour plus
your sweep interval. If that is too long, put the queue on a connection the
application does not open transactions on, or use a queue driver outside the
database.

You bind the deliverer, because only your application knows where each
partner's `/notifications` endpoint is and which credentials to use:

```php
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliveryFailed;
use LambdaTwelve\OneRecord\Laravel\Notifications\DeliveryRejected;
use LambdaTwelve\OneRecord\Laravel\Notifications\NotificationDeliverer;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\PendingNotification;

final class PartnerDeliverer implements NotificationDeliverer
{
    public function deliver(PendingNotification $pending): void
    {
        $endpoint = $pending->endpoint;                    // suggested from the recipient IRI, may be null
        $document = $pending->outbound->notification->toJsonLd();
        // POST $document to the partner with its token; throw DeliveryFailed to retry,
        // DeliveryRejected to give up.
    }
}

$this->app->bind(NotificationDeliverer::class, PartnerDeliverer::class);
```

A default deliverer built on the SDK's client arrives when that client is
released. Until a deliverer is bound, rows wait and a warning is logged;
nothing is lost.

Commands: `one-record:outbox:deliver [--limit=100] [--inline]` picks up due
rows (schedule it every minute as the safety net, or as the only mechanism
with `--inline` and `outbox.dispatch=none`), `one-record:outbox:retry {id}`
requeues a given-up row, `one-record:outbox:prune` removes old delivered and
failed rows.

## Replacing any part

Everything is a container binding of an SDK interface. Bind your own
implementation of any of these and the SDK uses it:

`LogisticsObjectStore`, `LogisticsEventStore`, `ActionRequestStore`,
`SubscriptionStore`, `AccessDelegationStore`, `NotificationOutbox`,
`UnitOfWork`, `Authenticator`, `AccessPolicy` (all `LambdaTwelve\OneRecord\Server\Spi`),
`ClientCredentialsVerifier` (`LambdaTwelve\OneRecord\Auth`), `IriMinter`
(`LambdaTwelve\OneRecord\Model`), and the PSR services `ClockInterface`,
`EventDispatcherInterface`, `ClientInterface`, the PSR-17 factories. The SDK's
logger and cache are the container keys `one-record.logger` and
`one-record.cache`.

Type-hint `Services`, `OneRecordServer`, `DataHolder`, `ActionRequests` or
`ServerConfig` anywhere Laravel injects.

## Local development

The project uses [DDEV](https://ddev.com); no host PHP is needed. The SDK
comes from Packagist like any other dependency:

```sh
git clone git@github.com:lambda-twelve/one-record-laravel.git
cd one-record-laravel
ddev start
ddev composer install
ddev test                  # PHPUnit on SQLite
ddev test --db=mariadb     # on MariaDB
ddev test --db=pgsql       # on PostgreSQL
ddev phpstan               # level max, strict rules
ddev cs                    # php-cs-fixer (ddev cs fix to apply)
```

To work against an unreleased SDK, point a path repository at a sibling
checkout (`composer config repositories.sdk path ../one-record`, then
`composer require "lambda-twelve/one-record:@dev"`) and mount that checkout
into the web container with a `.ddev/docker-compose.*.yaml` of your own;
neither change belongs in a commit.

The test suite drives the real SDK: the SDK's shipped store contracts
(`LambdaTwelve\OneRecord\Testing\Contract`) run against the database stores,
the host's own store tests run against the SDK's in-memory stores (the
reference) and the database stores, and the end-to-end flow runs on both
drivers. CI does the same on PHP 8.3 to 8.5,
Laravel 12 and 13, lowest and highest dependencies, SQLite, MariaDB and
PostgreSQL. See `CONTRIBUTING.md`.

## Not yet supported

- A default `NotificationDeliverer` and a partner client factory over the
  SDK's PSR-18 client: pending the SDK's client release.
- Multiple data holders in one application: the SDK serves one data holder
  per `Services`; resolve a per-tenant server yourself if you need more.

## Licence

Apache-2.0. The ONE Record specification and ontologies are IATA's, licensed
under the MIT License; see `NOTICE`.
