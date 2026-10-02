<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\InMemory;

use LambdaTwelve\OneRecord\Laravel\Tests\Contract\NotificationOutboxContract;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryNotificationOutboxTest extends TestCase
{
    use NotificationOutboxContract;

    private ?InMemoryNotificationOutbox $outbox = null;

    protected function outbox(): NotificationOutbox
    {
        return $this->outbox ??= new InMemoryNotificationOutbox();
    }

    protected function enqueued(): array
    {
        $outbox = $this->outbox();
        \assert($outbox instanceof InMemoryNotificationOutbox);

        return $outbox->all();
    }
}
