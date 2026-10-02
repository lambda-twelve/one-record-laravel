<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications\Events;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\PendingNotification;

final readonly class NotificationDelivered
{
    public function __construct(public PendingNotification $notification) {}
}
