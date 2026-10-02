# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
