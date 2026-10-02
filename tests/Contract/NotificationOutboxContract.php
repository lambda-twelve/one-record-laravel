<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

trait NotificationOutboxContract
{
    abstract protected function outbox(): NotificationOutbox;

    /**
     * Everything enqueued so far, oldest first.
     *
     * @return list<OutboundNotification>
     */
    abstract protected function enqueued(): array;

    public function testNotificationsAreKeptWholeUntilTheHostDeliversThem(): void
    {
        $recipient = new Iri('https://partner.example/logistics-objects/partner');
        $piece = Documents::piece('p1');
        $this->outbox()->enqueue(new OutboundNotification(
            $recipient,
            new Notification(NotificationEventType::LogisticsObjectUpdated, $piece->iri, Cargo::Piece, new Iri(Documents::BASE . '/action-requests/c1'), [Cargo::goodsDescription], [], $piece),
            Documents::at('2026-10-02T12:00:00.000Z'),
        ));
        $this->outbox()->enqueue(new OutboundNotification(
            new Iri('https://agent.example/no-logistics-objects-path'),
            new Notification(NotificationEventType::LogisticsObjectCreated, Documents::iri('p2'), Cargo::Piece),
            Documents::at('2026-10-02T12:01:00.000Z'),
        ));

        $all = $this->enqueued();

        self::assertCount(2, $all);
        self::assertSame($recipient->value, $all[0]->recipient->value);
        self::assertSame('https://partner.example/notifications', $all[0]->suggestedEndpoint());
        self::assertSame(NotificationEventType::LogisticsObjectUpdated, $all[0]->notification->eventType);
        self::assertSame($piece->iri->value, $all[0]->notification->logisticsObject?->value);
        self::assertSame(Cargo::Piece, $all[0]->notification->logisticsObjectType);
        self::assertSame(Documents::BASE . '/action-requests/c1', $all[0]->notification->triggeredBy?->value);
        self::assertSame([Cargo::goodsDescription], $all[0]->notification->changedProperties);
        self::assertNotNull($all[0]->notification->body);
        self::assertTrue($all[0]->notification->body->isSameAs($piece));
        self::assertEquals(Documents::at('2026-10-02T12:00:00.000Z'), $all[0]->createdAt);

        self::assertNull($all[1]->suggestedEndpoint());
        self::assertNull($all[1]->notification->body);
        self::assertSame(NotificationEventType::LogisticsObjectCreated, $all[1]->notification->eventType);
    }
}
