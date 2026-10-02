# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
