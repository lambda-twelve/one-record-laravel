<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications;

use RuntimeException;

/**
 * A delivery that will never succeed as it is; the row is marked failed for
 * an operator to look at (and retry with one-record:outbox:retry after fixing
 * the cause).
 */
class DeliveryRejected extends RuntimeException {}
