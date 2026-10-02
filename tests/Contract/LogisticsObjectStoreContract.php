<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

/**
 * The behaviour every LogisticsObjectStore must show, run against the SDK's
 * in-memory store (the reference) and the database store.
 */
trait LogisticsObjectStoreContract
{
    abstract protected function objects(): LogisticsObjectStore;

    public function testCreateStoresRevisionOne(): void
    {
        $piece = Documents::piece('p1');
        $at = Documents::at('2026-10-02T10:00:00.250Z');

        $stored = $this->objects()->create($piece, $at);

        self::assertSame(1, $stored->revision);
        self::assertSame(1, $stored->latestRevision);
        self::assertEquals($at, $stored->createdAt);
        self::assertEquals($at, $stored->lastModified);
        self::assertTrue($stored->isLatest());
        self::assertTrue($this->objects()->exists($piece->iri));
        self::assertFalse($this->objects()->exists(Documents::iri('nope')));

        $read = $this->objects()->latest($piece->iri);
        self::assertNotNull($read);
        self::assertTrue($read->object->isSameAs($piece));
        self::assertSame($piece->iri->value, $read->object->iri->value);
        self::assertSame([Cargo::Piece], $read->object->types());
        self::assertSame('Perishables', $read->object->literal(Cargo::goodsDescription));
    }

    public function testEveryReadCarriesTheLatestRevisionAndTheCreationTime(): void
    {
        $created = Documents::at('2026-10-02T10:00:00.000Z');
        $this->objects()->create(Documents::piece('p1', 'v1'), $created);
        $this->objects()->saveRevision(Documents::piece('p1', 'v2'), 1, Documents::at('2026-10-02T10:05:00.000Z'));
        $third = $this->objects()->saveRevision(Documents::piece('p1', 'v3'), 2, Documents::at('2026-10-02T10:10:00.000Z'));

        self::assertSame(3, $third->revision);
        self::assertSame(3, $third->latestRevision);
        self::assertEquals($created, $third->createdAt);

        $latest = $this->objects()->latest(Documents::iri('p1'));
        self::assertNotNull($latest);
        self::assertSame('v3', $latest->object->literal(Cargo::goodsDescription));
        self::assertSame(3, $latest->revision);

        $second = $this->objects()->revision(Documents::iri('p1'), 2);
        self::assertNotNull($second);
        self::assertSame('v2', $second->object->literal(Cargo::goodsDescription));
        self::assertSame(2, $second->revision);
        self::assertSame(3, $second->latestRevision);
        self::assertEquals($created, $second->createdAt);
        self::assertEquals(Documents::at('2026-10-02T10:05:00.000Z'), $second->lastModified);
        self::assertFalse($second->isLatest());

        self::assertNull($this->objects()->revision(Documents::iri('p1'), 0));
        self::assertNull($this->objects()->revision(Documents::iri('p1'), 4));
        self::assertNull($this->objects()->revision(Documents::iri('other'), 1));
        self::assertNull($this->objects()->latest(Documents::iri('other')));
    }

    public function testAtAnswersTheRevisionCurrentAtAnInstant(): void
    {
        $iri = Documents::iri('p1');
        $this->objects()->create(Documents::piece('p1', 'v1'), Documents::at('2026-10-02T10:00:00.000Z'));
        $this->objects()->saveRevision(Documents::piece('p1', 'v2'), 1, Documents::at('2026-10-02T10:05:00.000Z'));

        self::assertNull($this->objects()->at($iri, Documents::at('2026-10-02T09:59:59.999Z')));
        self::assertSame(1, $this->objects()->at($iri, Documents::at('2026-10-02T10:00:00.000Z'))?->revision);
        self::assertSame(1, $this->objects()->at($iri, Documents::at('2026-10-02T10:04:59.999Z'))?->revision);
        self::assertSame(2, $this->objects()->at($iri, Documents::at('2026-10-02T10:05:00.000Z'))?->revision);
        self::assertSame(2, $this->objects()->at($iri, Documents::at('2027-01-01T00:00:00.000Z'))?->revision);
        self::assertSame(2, $this->objects()->at($iri, Documents::at('2026-10-02T10:01:00.000Z'))?->latestRevision);
        self::assertNull($this->objects()->at(Documents::iri('other'), Documents::at('2027-01-01T00:00:00.000Z')));
    }

    public function testCreatingAnExistingObjectIsRefused(): void
    {
        $this->objects()->create(Documents::piece('p1'), Documents::at('2026-10-02T10:00:00.000Z'));

        try {
            $this->objects()->create(Documents::piece('p1', 'again'), Documents::at('2026-10-02T10:01:00.000Z'));
            self::fail('Expected ALREADY_EXISTS');
        } catch (StoreException $e) {
            self::assertSame(StoreException::ALREADY_EXISTS, $e->kind);
        }
        self::assertSame('Perishables', $this->objects()->latest(Documents::iri('p1'))?->object->literal(Cargo::goodsDescription));
    }

    public function testAStaleRevisionIsAConflictAndNothingIsWritten(): void
    {
        $this->objects()->create(Documents::piece('p1', 'v1'), Documents::at('2026-10-02T10:00:00.000Z'));
        $this->objects()->saveRevision(Documents::piece('p1', 'v2'), 1, Documents::at('2026-10-02T10:05:00.000Z'));

        try {
            $this->objects()->saveRevision(Documents::piece('p1', 'stale'), 1, Documents::at('2026-10-02T10:06:00.000Z'));
            self::fail('Expected REVISION_CONFLICT');
        } catch (StoreException $e) {
            self::assertSame(StoreException::REVISION_CONFLICT, $e->kind);
            self::assertStringContainsString('2', $e->getMessage());
        }
        $latest = $this->objects()->latest(Documents::iri('p1'));
        self::assertNotNull($latest);
        self::assertSame(2, $latest->latestRevision);
        self::assertSame('v2', $latest->object->literal(Cargo::goodsDescription));
        self::assertNull($this->objects()->revision(Documents::iri('p1'), 3));
    }

    public function testRevisingAnUnknownObjectIsNotFound(): void
    {
        try {
            $this->objects()->saveRevision(Documents::piece('ghost'), 1, Documents::at('2026-10-02T10:00:00.000Z'));
            self::fail('Expected NOT_FOUND');
        } catch (StoreException $e) {
            self::assertSame(StoreException::NOT_FOUND, $e->kind);
        }
        self::assertFalse($this->objects()->exists(Documents::iri('ghost')));
    }

    public function testEraseRemovesEveryRevision(): void
    {
        $iri = Documents::iri('p1');
        $this->objects()->create(Documents::piece('p1', 'v1'), Documents::at('2026-10-02T10:00:00.000Z'));
        $this->objects()->saveRevision(Documents::piece('p1', 'v2'), 1, Documents::at('2026-10-02T10:05:00.000Z'));
        $this->objects()->create(Documents::piece('p2'), Documents::at('2026-10-02T10:00:00.000Z'));

        $this->objects()->erase($iri);

        self::assertFalse($this->objects()->exists($iri));
        self::assertNull($this->objects()->latest($iri));
        self::assertNull($this->objects()->revision($iri, 1));
        self::assertNull($this->objects()->at($iri, Documents::at('2027-01-01T00:00:00.000Z')));
        self::assertTrue($this->objects()->exists(Documents::iri('p2')));

        // The IRI is free again afterwards.
        self::assertSame(1, $this->objects()->create(Documents::piece('p1', 'v1 again'), Documents::at('2026-10-02T11:00:00.000Z'))->revision);
    }
}
