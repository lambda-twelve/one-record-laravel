<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Notifications;

use RuntimeException;

/**
 * A delivery attempt that may succeed later; the job backs off and retries.
 */
class DeliveryFailed extends RuntimeException {}
