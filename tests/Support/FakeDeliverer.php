<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use LambdaTwelve\OneRecord\Laravel\Notifications\NotificationDeliverer;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\PendingNotification;
use Throwable;

/**
 * Records what it was asked to deliver and fails on demand.
 */
final class FakeDeliverer implements NotificationDeliverer
{
    /** @var list<PendingNotification> */
    public array $delivered = [];

    /** @var list<Throwable> */
    private array $failures = [];

    public function failWith(Throwable ...$failures): void
    {
        array_push($this->failures, ...$failures);
    }

    public function deliver(PendingNotification $pending): void
    {
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw $failure;
        }
        $this->delivered[] = $pending;
    }
}
