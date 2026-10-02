<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\JsonLd\JsonLd;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;

/**
 * Append-only events, the JSON-LD plus the columns the spec's filters need
 * (event code, event date, creation date). Date bounds and sorting run in
 * SQL with exactly the reference store's semantics (strict bounds, events
 * without an eventDate excluded by occurred filters, created falls back to
 * the receipt time). Event codes are matched in PHP by the SDK's own
 * matchesCode(), after a LIKE pre-filter narrows the rows, because that
 * match is case-sensitive and LIKE is not everywhere.
 *
 * Two deliberate differences from the in-memory store: appending an event
 * IRI twice fails (events are immutable) and lastModified() is the newest
 * event rather than the last appended one.
 */
final class DatabaseLogisticsEventStore implements LogisticsEventStore
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
    ) {}

    public function append(LogisticsEvent $event): void
    {
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

        $pageInSql = $query->eventCodes === [] && $query->limit !== null;
        if ($pageInSql) {
            $q->offset($query->skip)->limit($query->limit);
        }

        $events = array_map($this->hydrate(...), Row::all($q));
        if ($query->eventCodes !== []) {
            $events = array_values(array_filter($events, static function (LogisticsEvent $event) use ($query): bool {
                foreach ($query->eventCodes as $code) {
                    if ($event->matchesCode($code)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $pageInSql ? $events : \array_slice($events, $query->skip, $query->limit);
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
