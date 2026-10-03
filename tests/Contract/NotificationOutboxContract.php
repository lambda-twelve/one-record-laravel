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

/**
 * Mirrors the SDK's Testing\Contract\NotificationOutboxContract (a TestCase,
 * which Testbench's base class cannot also extend): identity, order and the
 * document kept whole, and snapshots on enqueue and on read. Keep the two in
 * step.
 */
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
            'n-1',
        ));
        $this->outbox()->enqueue(new OutboundNotification(
            new Iri('https://agent.example/no-logistics-objects-path'),
            new Notification(NotificationEventType::LogisticsObjectCreated, Documents::iri('p2'), Cargo::Piece),
            Documents::at('2026-10-02T12:01:00.000Z'),
            'n-2',
        ));

        $all = $this->enqueued();

        self::assertCount(2, $all);
        self::assertSame(['n-1', 'n-2'], array_map(static fn(OutboundNotification $n): string => $n->id, $all), 'the SDK\'s notification ids survive storage (R4-003)');
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

    public function testQueuedBodiesAndReadsAreSnapshots(): void
    {
        $recipient = new Iri('https://partner.example/logistics-objects/partner');
        $piece = Documents::piece('p1');
        $this->outbox()->enqueue(new OutboundNotification($recipient, new Notification(NotificationEventType::LogisticsObjectCreated, $piece->iri, Cargo::Piece, null, [], [], $piece), Documents::at('2026-10-02T12:00:00.000Z'), 'n-1'));

        // Neither the caller's object nor what a read handed out may change what is delivered.
        $piece->graph->add(new \LambdaTwelve\OneRecord\Rdf\Triple($piece->iri, new Iri(Cargo::goodsDescription), \LambdaTwelve\OneRecord\Rdf\Literal::string('AFTER-ENQUEUE')));
        $first = $this->enqueued();
        self::assertCount(1, $first);
        $first[0]->notification->body?->graph->add(new \LambdaTwelve\OneRecord\Rdf\Triple($piece->iri, new Iri(Cargo::goodsDescription), \LambdaTwelve\OneRecord\Rdf\Literal::string('AFTER-READ')));
        $second = $this->enqueued();
        self::assertSame('n-1', $second[0]->id);
        $json = json_encode($second[0]->notification->toJsonLd(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('AFTER-ENQUEUE', $json);
        self::assertStringNotContainsString('AFTER-READ', $json);
    }
}
