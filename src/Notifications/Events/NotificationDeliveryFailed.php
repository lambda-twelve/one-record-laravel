<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications\Events;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\PendingNotification;

/**
 * Raised after every failed attempt; $final tells whether the row has been
 * given up on (dead letter) or will be retried.
 */
final readonly class NotificationDeliveryFailed
{
    public function __construct(
        public PendingNotification $notification,
        public string $error,
        public bool $final,
    ) {}
}
