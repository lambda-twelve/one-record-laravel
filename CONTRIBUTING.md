# Contributing

Thank you for helping make ONE Record a natural Laravel citizen.

## Ground rules

- **Thin by design.** This package adapts `lambda-twelve/one-record` to
  Laravel: container bindings, routes, request bridging, database stores,
  queue and console glue. Protocol semantics, JSON-LD, validation and the
  action-request lifecycle live in the SDK. If you find yourself writing any
  of those here, open an issue on the SDK instead. PHPStan enforces that
  `src/` never reaches into the SDK's server internals.
- **The SDK's model stays the SDK's model.** Stores persist the SDK's value
  objects; no Eloquent models wrap them.
- **Tests first.** The SDK's shipped store contracts
  (`LambdaTwelve\OneRecord\Testing\Contract`) run against the database stores
  in `tests/Contract/Sdk`; host behaviour beyond them is tested against both
  the SDK's in-memory store and the database store. Integration behaviour is
  proven through the real SDK, not mocks. PHPStan stays clean at level max
  without a baseline.

## Working locally

The project uses [DDEV](https://ddev.com) so no host PHP is needed. The SDK
comes from Packagist; the README says how to work against a local checkout
instead.

```sh
ddev start
ddev composer install
ddev test                  # PHPUnit on SQLite
ddev test --db=mariadb     # the same suite on MariaDB
ddev test --db=pgsql       # ... and on PostgreSQL
ddev phpstan               # static analysis
ddev cs                    # code style check (ddev cs fix to apply)
```

Without DDEV, the equivalent Composer scripts are `composer test`,
`composer phpstan`, `composer cs` and `composer cs:fix`.

## Commits and pull requests

- Small commits with a clear subject and a body that says why.
- Update `CHANGELOG.md` under *Unreleased* for user-visible changes.

## Licence

By contributing you agree that your contributions are licensed under the
Apache License 2.0 that covers this project.
