<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Sdk;

use DateTimeImmutable;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Testing\Contract\NotificationOutboxContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseNotificationOutbox::class)]
final class DatabaseNotificationOutboxContractTest extends NotificationOutboxContract
{
    use UsesTheDatabaseStores;

    protected function createOutbox(): NotificationOutbox
    {
        return new DatabaseNotificationOutbox($this->connection(), $this->tables());
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
