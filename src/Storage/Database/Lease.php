<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

/**
 * A worker's claim on one outbox row. The token is minted by the claim and
 * required by every outcome, so a worker whose lease expired while it was
 * delivering cannot overwrite what the worker now holding the row records.
 * The row is read back under the lease, so its attempt count is this attempt.
 */
final readonly class Lease
{
    public function __construct(
        public string $token,
        public PendingNotification $pending,
    ) {}
}
