# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Nothing yet.

## [1.0.0-beta1] - 2026-10-04

First release: the Laravel host for `lambda-twelve/one-record` 1.0.0-beta3.
The provider wires the SDK's server, PHP API and token endpoint into the
container, routes and console; the database stores pass the SDK's shipped
contracts on SQLite, MariaDB and PostgreSQL; the outbox delivers through the
host's queue; and four independent adversarial review rounds of this package
are folded in, each with its reproduction probes kept as regression tests.
Public API changes remain possible between betas and are recorded here.

Not in this beta: a default `NotificationDeliverer` (the SDK's client now
exists, so it is the first thing after this); more than one data holder per
application. Delivery is at least once, and with the `database` queue driver
an application rollback can delay a notification by up to an hour (see the
README's outbox section). Not yet exercised anywhere: a queue worker against a
real partner, production load, and interoperability of this host with another
ONE Record server.

### Changed

- The SDK is required from Packagist as `lambda-twelve/one-record:^1.0.0-beta3`.
  The path repository, the sibling checkout in CI and the DDEV mount are gone.
  Applications must allow the pre-release themselves, since Composer reads
  stability flags from the root `composer.json` only:
  `composer require "lambda-twelve/one-record:^1.0.0-beta3"`. beta3 carries
  what this package's integration asked of the SDK: every action-request
  decision is a compare-and-set before any side effect, `Services` warns when
  persistent stores come without a unit of work (the provider always binds
  one), and the contract traits' constants are prefixed.
- The SDK's shipped store contracts run against the database stores as the
  provider wires them, as the traits
  (`LambdaTwelve\OneRecord\Testing\Contract\*ContractTests`) on the package's
  own Testbench base in `tests/Contract/Sdk`. The mirrored object-store and
  outbox traits are gone; the remaining host traits keep only the behaviour
  the SDK's contracts leave open.
- The SDK's `UnitOfWork` is bound to the storage connection
  (`DatabaseUnitOfWork`), so every mutating request and every `DataHolder` /
  `ActionRequests` operation is one transaction and nested calls are
  savepoints. `storage.transactions` now only adds the request-wide
  envelope. `DB::transaction()` around your own `DataHolder` calls is no
  longer needed.
- Outbox outcomes are recorded under a lease: `claim()` returns a `Lease`
  (token plus the row as claimed, attempt count included) or null, and
  `markDelivered()`, `markRetry()` and `markFailed()` take the lease and
  return whether it still held. The outbox table gains `lease_token`.
- `DeliverNotification` is unique per row until processing starts
  (`ShouldBeUniqueUntilProcessing`, an hour at most) and is always queued
  through `DeliverNotification::dispatchFor()`, which takes that lock and
  gives it back when the queue refuses the job. The outbox itself defers
  that call to the commit of the enqueuing transaction; the job no longer
  carries `afterCommit`.
- `lease_token` arrives through a forward migration
  (`2026_10_04_000000_add_lease_token_to_one_record_outbox`), so a database
  migrated before it keeps its rows and gains the column from `migrate`.

### Fixed

Findings of the third adversarial review (2026-10-04, against the fixes
below), kept as regression tests in `tests/Feature/AdversarialReview3Test.php`
and `OutboxDeliveryTest`:

- A queue connection configured with `after_commit` deferred the delivery
  job's submission to the commit of whatever other transaction the
  application had open, past the point where a failed submission could give
  the unique-job lock back. The job now says `beforeCommit()` explicitly;
  the outbox already waits for the enqueuing transaction itself.
- The rollback test never reached the enqueue (the SDK dispatches
  `LogisticsObjectCreated` before the fan-out); it now enqueues inside a
  transaction, checks the row and the absence of a job, rolls back and checks
  both are gone.
- The fourth review confirmed the remaining limitation and asked for it in
  the operator documentation: with the `database` queue driver on a
  connection that has its own transaction open, an application rollback takes
  the just-queued job with it and the unique lock delays the next queuing by
  up to an hour. The README's outbox section says so; a test pins it.

Findings of the second adversarial review (2026-10-04, against the fixes
below), kept as regression tests in `tests/Feature/AdversarialReview2Test.php`:

- Existing installations did not receive the `lease_token` column, because it
  had been added to the already-applied table-creation migration; claims then
  failed on the missing column. It now comes from a forward migration.
- A queue that refused a delivery job left the unique-job lock behind, so
  sweeps skipped the row for up to an hour after the queue recovered. The
  lock is released when the dispatch throws.
- The README still called public grants process-local; the SDK's
  `GrantAccessPolicy::allowEveryone()` writes them through the grant store,
  which the database driver persists.
- The test environment pins the array cache and the sync queue, so a
  generated Testbench `.env` cannot select stores the test database lacks.

Findings of the first adversarial review of 2026-10-04, each kept as a
regression test in `tests/Feature/AdversarialReviewTest.php` and
`tests/Feature/FreshInstallTest.php`:

- An action-request decision that lost the status race to a competing
  decision answered 409 but left the grants it had written committed, so the
  partner was allowed in; and a `DataHolder` operation interrupted by a
  throwing listener stayed half persisted. The unit of work above unwinds
  both.
- A worker whose lease had expired could still record a permanent failure
  over the retry the worker now holding the row had scheduled, leaving the
  row undeliverable for good. Outcomes now require the lease token, and the
  attempt count comes from the claim rather than an earlier unlocked read.
- A freshly installed application did not boot: mounting the routes
  resolved the complete `ServerConfig`, which refuses the unset data holder,
  before the operator could publish the configuration or run `artisan`.
  Routes now read only the base path; the server identity is validated when
  the server is first needed.
- Verifying an unknown client id made a throwaway hash and then checked it,
  one hash operation more than a known id, per PHP process. The throwaway
  hash is now made with the configured hasher independently of the id and
  kept in the package cache; both paths do one check.
- The delivery job's `ShouldBeUnique` was ineffective because it was
  dispatched through the bus contract, which never takes the unique lock;
  repeated sweeps queued the same row again while workers lagged.
- Warnings raised from test code were hidden by `restrictWarnings` in the
  PHPUnit configuration, and the manifest test still iterated the removed
  `repositories` key. Warnings anywhere now fail the run.
- The README's authorisation example checked for `InMemoryAccessPolicy`
  while the provider binds `GrantAccessPolicy`, so its grant never ran.
- Code-filtered event queries hydrated every candidate row before paging;
  they now walk the ordered rows in chunks of 200 until the page is full.

- Appending a logistics event whose IRI already exists raises the SDK's
  `StoreException` (`ALREADY_EXISTS`) from the database store, as the SPI
  requires, instead of letting the database's unique-index error escape. The
  SDK's shipped `LogisticsEventStoreContract` caught it.
- The database outbox stores the SDK's notification id in its own column
  (unique) and hands it back on read; it used to replace it with the row key,
  so the identity the server assigned, and the `Idempotency-Key` sent with the
  notification, was lost. The outbox contract test now asserts the ids and
  that reads are snapshots, as the SDK's shipped contract does.

### Added

- Notification delivery: a queued job that leases an outbox row, hands it to
  the host's `NotificationDeliverer` and records the outcome with backoff and
  a dead-letter state; `one-record:outbox:deliver`, `one-record:outbox:retry`
  and `one-record:outbox:prune`; `NotificationDelivered` and
  `NotificationDeliveryFailed` events.
- Token issuing for this host's own partners: the SDK's client-credentials
  token endpoint on a configurable route, credentials stored hashed in the
  database, a JWKS route publishing the signing key, and
  `one-record:client:create` to register a partner.
- `database` storage driver: query-builder implementations of every SDK
  store SPI (logistics objects with one row per revision and an atomic
  compare-and-set for new revisions, logistics events with the spec's
  filters in SQL, action requests, subscriptions derived from accepted
  requests plus a table of offered subscriptions, grants, the notification
  outbox) and the migration that creates their tables. Contract tests run
  the same behaviour against the SDK's in-memory stores and the database
  stores on SQLite, MariaDB and PostgreSQL.
- Every request the server handles runs in a database transaction and is
  rolled back when the SDK answers 5xx.
- Service provider that wires the SDK into the container: `ServerConfig` from
  `config/one-record.php`, PSR-20 clock over Carbon, PSR-14 bridge to
  Laravel's event dispatcher, Guzzle as PSR-17/PSR-18, the SDK's JWT
  authenticator built from configured issuers (static keys and JWKS), the
  SDK's grant-based access policy with configurable internal agents.
- Route mounting under the configured base path (`OneRecord::routes()` or
  automatic) and the request/response bridge between Laravel and the SDK's
  PSR-15 server.
- `array` storage driver backed by the SDK's in-memory stores, for tests.
- Package skeleton: Composer manifest, DDEV environment, CI, coding standards,
  PHPStan at level max with a rule that keeps the SDK's server internals out
  of this package.
