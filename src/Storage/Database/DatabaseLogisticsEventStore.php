<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\JsonLd\JsonLd;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;

/**
 * Append-only events, the JSON-LD plus the columns the spec's filters need
 * (event code, event date, creation date). Date bounds and sorting run in
 * SQL with exactly the reference store's semantics (strict bounds, events
 * without an eventDate excluded by occurred filters, created falls back to
 * the receipt time). Event codes are matched in PHP by the SDK's own
 * matchesCode(), after a LIKE pre-filter narrows the rows, because that
 * match is case-sensitive and LIKE is not everywhere. As in the reference
 * store, an event IRI is appended once and lastModified() is the newest
 * event, not the last appended one.
 */
final class DatabaseLogisticsEventStore implements LogisticsEventStore
{
    use Transactions;

    /** Rows hydrated at a time while a code filter is paged in PHP. */
    private const int CHUNK = 200;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
    ) {}

    protected function connection(): ConnectionInterface
    {
        return $this->db;
    }

    public function append(LogisticsEvent $event): void
    {
        // The unique index on the IRI is the check (AR-022: one IRI on the whole server, whichever
        // object it is filed under); the savepoint keeps a refused insert from poisoning PostgreSQL's
        // enclosing request transaction.
        $this->transaction(function () use ($event): void {
            try {
                $this->db->table($this->tables->events())->insert([
                    'iri_hash' => IriHash::of($event->iri),
                    'iri' => $event->iri->value,
                    'logistics_object_hash' => IriHash::of($event->logisticsObject),
                    'logistics_object_iri' => $event->logisticsObject->value,
                    'event_code' => $event->eventCode(),
                    'event_date' => Timestamps::toDb($event->eventDate()),
                    'creation_date' => Timestamps::toDb($event->creationDate()),
                    'created_at' => Timestamps::toDb($event->created),
                    'document' => Json::encode($event->toJsonLd(), false),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw StoreException::alreadyExists($event->iri);
            }
        });
    }

    public function get(Iri $eventIri): ?LogisticsEvent
    {
        $row = Row::first($this->db->table($this->tables->events())->where('iri_hash', IriHash::of($eventIri)));

        return $row === null ? null : $this->hydrate($row);
    }

    public function query(Iri $logisticsObject, EventQuery $query): array
    {
        $q = $this->db->table($this->tables->events())->where('logistics_object_hash', IriHash::of($logisticsObject));
        if ($query->createdAfter !== null) {
            $q->whereRaw('COALESCE(creation_date, created_at) > ?', [Timestamps::toDb($query->createdAfter)]);
        }
        if ($query->createdBefore !== null) {
            $q->whereRaw('COALESCE(creation_date, created_at) < ?', [Timestamps::toDb($query->createdBefore)]);
        }
        if ($query->occurredAfter !== null) {
            $q->whereNotNull('event_date')->where('event_date', '>', Timestamps::toDb($query->occurredAfter));
        }
        if ($query->occurredBefore !== null) {
            $q->whereNotNull('event_date')->where('event_date', '<', Timestamps::toDb($query->occurredBefore));
        }
        if ($query->eventCodes !== []) {
            $q->where(static function (Builder $where) use ($query): void {
                foreach ($query->eventCodes as $code) {
                    $escaped = addcslashes($code, '%_\\');
                    $where->orWhere('event_code', $code)
                        ->orWhere('event_code', 'like', '%#' . $escaped)
                        ->orWhere('event_code', 'like', '%/' . $escaped);
                }
            });
        }

        $direction = str_starts_with($query->sort, 'DESC') ? 'desc' : 'asc';
        $key = str_ends_with($query->sort, 'eventDate') ? 'COALESCE(event_date, created_at)' : 'COALESCE(creation_date, created_at)';
        $q->orderByRaw($key . ' ' . $direction)->orderBy('iri', $direction);

        if ($query->eventCodes !== []) {
            return $this->pageMatchingCodes($q, $query);
        }
        if ($query->skip > 0) {
            // SQLite and MySQL accept OFFSET only after a LIMIT; "no limit" is spelled as the largest one.
            $q->offset($query->skip)->limit($query->limit ?? PHP_INT_MAX);
        } elseif ($query->limit !== null) {
            $q->limit($query->limit);
        }

        return array_map($this->hydrate(...), Row::all($q));
    }

    /**
     * Code matching finishes in PHP, so the page is found by walking the
     * pre-filtered, ordered rows in bounded chunks until skip and limit are
     * satisfied: a small page over a long history costs a chunk, not the
     * whole history, in memory.
     *
     * @return list<LogisticsEvent>
     */
    private function pageMatchingCodes(Builder $q, EventQuery $query): array
    {
        $page = [];
        $matched = 0;
        $offset = 0;
        do {
            $rows = Row::all((clone $q)->offset($offset)->limit(self::CHUNK));
            foreach ($rows as $row) {
                $event = $this->hydrate($row);
                if (!self::matchesAny($event, $query->eventCodes)) {
                    continue;
                }
                if ($matched++ < $query->skip) {
                    continue;
                }
                $page[] = $event;
                if ($query->limit !== null && \count($page) >= $query->limit) {
                    return $page;
                }
            }
            $offset += self::CHUNK;
        } while (\count($rows) === self::CHUNK);

        return $page;
    }

    /**
     * @param list<string> $codes
     */
    private static function matchesAny(LogisticsEvent $event, array $codes): bool
    {
        foreach ($codes as $code) {
            if ($event->matchesCode($code)) {
                return true;
            }
        }

        return false;
    }

    public function lastModified(Iri $logisticsObject): ?DateTimeImmutable
    {
        return Timestamps::fromDbNullable($this->db->table($this->tables->events())->where('logistics_object_hash', IriHash::of($logisticsObject))->max('created_at'));
    }

    /**
     * Removes every event of an object. Not part of the SDK's SPI (its forget
     * operation erases only the object store); the host calls this from its
     * own data-protection flow.
     */
    public function eraseFor(Iri $logisticsObject): void
    {
        $this->db->table($this->tables->events())->where('logistics_object_hash', IriHash::of($logisticsObject))->delete();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LogisticsEvent
    {
        // Not fromJsonLd(): that validates a posted body; here we rebuild what we stored.
        return new LogisticsEvent(
            new Iri(Row::string($row, 'iri')),
            new Iri(Row::string($row, 'logistics_object_iri')),
            JsonLd::expand(Row::string($row, 'document'))->graph,
            Timestamps::fromDb($row['created_at']),
        );
    }
}
