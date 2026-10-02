# Security policy

## Reporting a vulnerability

Please report security issues privately to Lambda Twelve rather than through a
public issue. Use GitHub's private vulnerability reporting on this repository
("Report a vulnerability" under the Security tab). Include the affected
version, a description of the issue and, where possible, a proof of concept.

You will receive an acknowledgement within five working days. We aim to publish
a fix and a security advisory within 90 days of the report, sooner for issues
that are being exploited.

## Scope

In scope: everything under `src/`, `config/` and `database/` of this package:
the request bridging, the database stores, the authentication wiring, the
token and JWKS routes and the notification outbox.

Out of scope: the core SDK `lambda-twelve/one-record` (report to its own
repository), Laravel itself, and deployments of this package by third parties.

## Supported versions

Before 1.0.0, only the latest pre-release receives security fixes. From 1.0.0
on, the latest minor release of the current major version is supported.
