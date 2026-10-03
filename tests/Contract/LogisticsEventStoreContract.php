<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract;

use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;

/**
 * Filtering, sorting and paging exactly as the SDK's in-memory store does:
 * strict date bounds, events without an eventDate skipped by occurred
 * filters, created falling back to the receipt time, case-sensitive codes,
 * IRI as the tie-break. The SDK's shipped contract pins the basics and runs
 * against the database store in tests/Contract/Sdk; this trait covers the
 * finer points, against the in-memory store (the reference) and the database
 * store.
 */
trait LogisticsEventStoreContract
{
    abstract protected function events(): LogisticsEventStore;

    private function seedEvents(): void
    {
        // e1: happened 09:00, received 10:00, DEP
        $this->events()->append(Documents::event('p1', 'e1', '2026-10-02T10:00:00.000Z', eventDate: '2026-10-02T09:00:00.000Z', code: Documents::statusCode('DEP')));
        // e2: happens 11:00, says it was created 10:04, received 10:05, ARR
        $this->events()->append(Documents::event('p1', 'e2', '2026-10-02T10:05:00.000Z', eventDate: '2026-10-02T11:00:00.000Z', creationDate: '2026-10-02T10:04:00.000Z', code: Documents::statusCode('ARR')));
        // e3: no dates at all, received 10:10, FOH
        $this->events()->append(Documents::event('p1', 'e3', '2026-10-02T10:10:00.000Z', code: Documents::statusCode('FOH')));
        // another object's event never shows up
        $this->events()->append(Documents::event('p2', 'x1', '2026-10-02T10:00:00.000Z', eventDate: '2026-10-02T09:00:00.000Z', code: Documents::statusCode('DEP')));
    }

    /**
     * @return list<string>
     */
    private function ids(EventQuery $query, string $object = 'p1'): array
    {
        return array_map(static fn(LogisticsEvent $e): string => basename($e->iri->value), $this->events()->query(Documents::iri($object), $query));
    }

    public function testAppendedEventsCanBeReadBack(): void
    {
        $this->seedEvents();

        $event = $this->events()->get(new Iri(Documents::iri('p1')->value . '/logistics-events/e2'));
        self::assertNotNull($event);
        self::assertSame(Documents::iri('p1')->value, $event->logisticsObject->value);
        self::assertEquals(Documents::at('2026-10-02T10:05:00.000Z'), $event->created);
        self::assertEquals(Documents::at('2026-10-02T11:00:00.000Z'), $event->eventDate());
        self::assertEquals(Documents::at('2026-10-02T10:04:00.000Z'), $event->creationDate());
        self::assertSame(Documents::statusCode('ARR')->value, $event->eventCode());
        self::assertTrue($event->matchesCode('ARR'));
        self::assertNull($this->events()->get(new Iri(Documents::iri('p1')->value . '/logistics-events/nope')));
        self::assertSame(['e1', 'e2', 'e3'], $this->ids(EventQuery::all()));
        self::assertSame([], $this->ids(EventQuery::all(), 'p3'));
    }

    public function testCreatedBoundsAreStrictAndFallBackToTheReceiptTime(): void
    {
        $this->seedEvents();

        self::assertSame(['e3'], $this->ids(new EventQuery(createdAfter: Documents::at('2026-10-02T10:04:00.000Z'))));
        self::assertSame(['e2', 'e3'], $this->ids(new EventQuery(createdAfter: Documents::at('2026-10-02T10:03:59.999Z'))));
        self::assertSame(['e1'], $this->ids(new EventQuery(createdBefore: Documents::at('2026-10-02T10:04:00.000Z'))));
        self::assertSame(['e2'], $this->ids(new EventQuery(createdAfter: Documents::at('2026-10-02T10:00:00.000Z'), createdBefore: Documents::at('2026-10-02T10:10:00.000Z'))));
    }

    public function testOccurredBoundsAreStrictAndSkipEventsWithoutAnEventDate(): void
    {
        $this->seedEvents();

        self::assertSame(['e1', 'e2'], $this->ids(new EventQuery(occurredAfter: Documents::at('2026-10-02T08:00:00.000Z'))));
        self::assertSame(['e2'], $this->ids(new EventQuery(occurredAfter: Documents::at('2026-10-02T09:00:00.000Z'))));
        self::assertSame(['e1'], $this->ids(new EventQuery(occurredBefore: Documents::at('2026-10-02T11:00:00.000Z'))));
        self::assertSame([], $this->ids(new EventQuery(occurredBefore: Documents::at('2026-10-02T09:00:00.000Z'))));
    }

    public function testEventCodesMatchTheSdkWay(): void
    {
        $this->seedEvents();

        self::assertSame(['e1'], $this->ids(new EventQuery(eventCodes: ['DEP'])));
        self::assertSame(['e2', 'e3'], $this->ids(new EventQuery(eventCodes: ['ARR', 'FOH'])));
        self::assertSame(['e1'], $this->ids(new EventQuery(eventCodes: [Documents::statusCode('DEP')->value])));
        self::assertSame([], $this->ids(new EventQuery(eventCodes: ['dep'])), 'codes are case-sensitive');
        self::assertSame([], $this->ids(new EventQuery(eventCodes: ['EP'])), 'only whole codes after # or / match');
        self::assertSame(['e1'], $this->ids(new EventQuery(eventCodes: ['DEP', 'ARR'], occurredBefore: Documents::at('2026-10-02T10:00:00.000Z'))));
    }

    public function testSortingUsesTheRequestedDateWithTheReceiptTimeAsFallback(): void
    {
        $this->seedEvents();

        self::assertSame(['e1', 'e2', 'e3'], $this->ids(new EventQuery(sort: EventQuery::SORT_CREATED_ASC)));
        self::assertSame(['e3', 'e2', 'e1'], $this->ids(new EventQuery(sort: EventQuery::SORT_CREATED_DESC)));
        self::assertSame(['e1', 'e3', 'e2'], $this->ids(new EventQuery(sort: EventQuery::SORT_EVENT_ASC)));
        self::assertSame(['e2', 'e3', 'e1'], $this->ids(new EventQuery(sort: EventQuery::SORT_EVENT_DESC)));
    }

    public function testTiesBreakOnTheEventIriInTheSortDirection(): void
    {
        $this->events()->append(Documents::event('p1', 'b', '2026-10-02T12:00:00.000Z'));
        $this->events()->append(Documents::event('p1', 'a', '2026-10-02T12:00:00.000Z'));
        $this->events()->append(Documents::event('p1', 'c', '2026-10-02T12:00:00.000Z'));

        self::assertSame(['a', 'b', 'c'], $this->ids(new EventQuery(sort: EventQuery::SORT_CREATED_ASC)));
        self::assertSame(['c', 'b', 'a'], $this->ids(new EventQuery(sort: EventQuery::SORT_CREATED_DESC)));
        self::assertSame(['a', 'b', 'c'], $this->ids(new EventQuery(sort: EventQuery::SORT_EVENT_ASC)));
    }

    public function testPagingAppliesAfterFilteringAndSorting(): void
    {
        $this->seedEvents();

        self::assertSame(['e2'], $this->ids(new EventQuery(skip: 1, limit: 1)));
        self::assertSame(['e2', 'e3'], $this->ids(new EventQuery(skip: 1)));
        self::assertSame(['e1', 'e2'], $this->ids(new EventQuery(limit: 2)));
        self::assertSame([], $this->ids(new EventQuery(skip: 5)));
        self::assertSame(['e2'], $this->ids(new EventQuery(eventCodes: ['DEP', 'ARR', 'FOH'], skip: 1, limit: 1)));
        self::assertSame(['e2'], $this->ids(new EventQuery(sort: EventQuery::SORT_EVENT_DESC, limit: 1)));
    }

}
