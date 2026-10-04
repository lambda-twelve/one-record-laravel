<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Testing\Contract\NotificationOutboxContractTests;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseNotificationOutbox::class)]
final class DatabaseNotificationOutboxContractTest extends DatabaseTestCase
{
    use NotificationOutboxContractTests;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // This case is about the store; delivery jobs have their own test.
        $app->make(Repository::class)->set('one-record.outbox.dispatch', 'none');
    }

    protected function createOutbox(): NotificationOutbox
    {
        $outbox = $this->app()->make(NotificationOutbox::class);
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);

        return $outbox;
    }

    protected function pending(NotificationOutbox $outbox): array
    {
        self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
        $out = [];
        foreach ($outbox->due(new DateTimeImmutable('2099-01-01T00:00:00Z'), 100) as $id) {
            $row = $outbox->find($id);
            self::assertNotNull($row);
            $out[] = $row->outbound;
        }

        return $out;
    }
}
