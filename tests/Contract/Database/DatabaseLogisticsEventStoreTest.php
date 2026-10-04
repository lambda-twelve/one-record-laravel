<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Contract\Database;

use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseLogisticsEventStore;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\DatabaseTestCase;
use LambdaTwelve\OneRecord\Laravel\Tests\Contract\LogisticsEventStoreContract;
use LambdaTwelve\OneRecord\Laravel\Tests\Support\Documents;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseLogisticsEventStore::class)]
final class DatabaseLogisticsEventStoreTest extends DatabaseTestCase
{
    use LogisticsEventStoreContract;

    protected function events(): LogisticsEventStore
    {
        $store = $this->app()->make(LogisticsEventStore::class);
        self::assertInstanceOf(DatabaseLogisticsEventStore::class, $store);

        return $store;
    }

    /**
     * Code matching finishes in PHP, so the store pages through the
     * pre-filtered rows in chunks; a page past the first chunk, crossing a
     * chunk boundary, must be exactly what one pass over the history gives.
     */
    public function testACodeFilteredPageFarIntoTheHistoryIsExact(): void
    {
        $store = $this->events();
        for ($i = 0; $i < 450; ++$i) {
            $at = Documents::at('2026-10-02T00:00:00.000Z')->modify('+' . $i . ' seconds')->format('Y-m-d\\TH:i:s.v\\Z');
            $store->append(Documents::event('p1', 'e' . $i, $at, eventDate: $at, code: Documents::statusCode($i % 2 === 1 ? 'ARR' : 'DEP')));
        }

        $page = $store->query(Documents::iri('p1'), new EventQuery(eventCodes: ['ARR'], skip: 100, limit: 50));

        $expected = [];
        for ($i = 201; $i <= 299; $i += 2) {
            $expected[] = 'e' . $i;
        }
        self::assertSame($expected, array_map(static fn(LogisticsEvent $e): string => basename($e->iri->value), $page));
        self::assertCount(225, $store->query(Documents::iri('p1'), new EventQuery(eventCodes: ['ARR'])), 'no limit: every match');
        self::assertSame(['e449'], array_map(static fn(LogisticsEvent $e): string => basename($e->iri->value), $store->query(Documents::iri('p1'), new EventQuery(eventCodes: ['ARR'], skip: 224))), 'skip past the last chunk boundary');
    }
}
