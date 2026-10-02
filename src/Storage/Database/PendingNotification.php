<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;

/**
 * One outbox row as a delivery job sees it.
 */
final readonly class PendingNotification
{
    public function __construct(
        public int $id,
        public OutboundNotification $outbound,
        public ?string $endpoint,
        public int $attempts,
    ) {}
}
