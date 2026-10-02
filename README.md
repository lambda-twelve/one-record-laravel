# lambda-twelve/one-record-laravel

Laravel integration for [`lambda-twelve/one-record`](https://github.com/lambda-twelve/one-record),
the framework-agnostic PHP implementation of **IATA ONE Record**.

> **Status:** under construction alongside the SDK. Nothing is released yet.

```text
Laravel application
      ↓
lambda-twelve/one-record-laravel   (this package: provider, routes, stores, auth wiring, outbox)
      ↓
lambda-twelve/one-record           (the SDK: protocol, JSON-LD, model, server, client)
      ↓
PHP + PSR interfaces
```

Full documentation follows as the package lands. See `CONTRIBUTING.md` for the
local workflow.
