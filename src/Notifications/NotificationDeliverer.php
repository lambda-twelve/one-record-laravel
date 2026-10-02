<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\PendingNotification;

/**
 * Delivers one outbox row to its recipient. The host binds an implementation
 * (its partner registry decides where the recipient's /notifications endpoint
 * is and which credentials to use); the delivery job does the rest. A default
 * built on the SDK's client arrives with the SDK's client release.
 */
interface NotificationDeliverer
{
    /**
     * @throws DeliveryFailed when the attempt failed but another may succeed (network, 5xx, 429)
     * @throws DeliveryRejected when retrying cannot help (no endpoint, 4xx other than 408/429)
     */
    public function deliver(PendingNotification $pending): void;
}
