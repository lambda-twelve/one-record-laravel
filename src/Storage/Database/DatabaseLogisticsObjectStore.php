<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use LambdaTwelve\OneRecord\Laravel\Support\IriHash;
use LambdaTwelve\OneRecord\Laravel\Support\Timestamps;
use LambdaTwelve\OneRecord\Model\LogisticsObject;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\StoredObject;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;

/**
 * One head row per object (latest revision, creation time) and one row per
 * revision holding the JSON-LD the SDK writes. The head row is the
 * compare-and-set target for saveRevision(): an UPDATE conditioned on the
 * expected revision is atomic on every supported database, so two writers
 * racing on the same revision cannot both win.
 */
final class DatabaseLogisticsObjectStore implements LogisticsObjectStore
{
    use Transactions;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Tables $tables,
    ) {}

    public function latest(Iri $iri): ?StoredObject
    {
        return $this->stored(Row::first($this->read($iri)->whereColumn('r.revision', 'h.latest_revision')));
    }

    public function revision(Iri $iri, int $revision): ?StoredObject
    {
        if ($revision < 1) {
            return null;
        }

        return $this->stored(Row::first($this->read($iri)->where('r.revision', $revision)));
    }

    public function at(Iri $iri, DateTimeImmutable $at): ?StoredObject
    {
        // The highest revision written at or before $at, as the reference store does.
        return $this->stored(Row::first($this->read($iri)->where('r.created_at', '<=', Timestamps::toDb($at))->orderByDesc('r.revision')));
    }

    public function exists(Iri $iri): bool
    {
        return $this->db->table($this->tables->objects())->where('iri_hash', IriHash::of($iri))->exists();
    }

    public function create(LogisticsObject $object, DateTimeImmutable $at): StoredObject
    {
        return $this->transaction(function () use ($object, $at): StoredObject {
            $hash = IriHash::of($object->iri);
            $now = Timestamps::toDb($at);
            try {
                $this->db->table($this->tables->objects())->insert([
                    'iri_hash' => $hash,
                    'iri' => $object->iri->value,
                    'latest_revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw StoreException::alreadyExists($object->iri);
            }
            $this->insertRevision($object, $hash, 1, $now);

            return new StoredObject($object, 1, 1, $at, $at);
        });
    }

    public function saveRevision(LogisticsObject $object, int $expectedCurrent, DateTimeImmutable $at): StoredObject
    {
        return $this->transaction(function () use ($object, $expectedCurrent, $at): StoredObject {
            $hash = IriHash::of($object->iri);
            $next = $expectedCurrent + 1;
            $now = Timestamps::toDb($at);
            $updated = $this->db->table($this->tables->objects())
                ->where('iri_hash', $hash)
                ->where('latest_revision', $expectedCurrent)
                ->update(['latest_revision' => $next, 'updated_at' => $now]);
            if ($updated === 0) {
                $head = Row::first($this->db->table($this->tables->objects())->where('iri_hash', $hash));
                throw $head === null
                    ? StoreException::notFound($object->iri)
                    : StoreException::revisionConflict($object->iri, $expectedCurrent, Row::int($head, 'latest_revision'));
            }
            $head = Row::first($this->db->table($this->tables->objects())->where('iri_hash', $hash));
            if ($head === null) {
                throw StoreException::notFound($object->iri);
            }
            $this->insertRevision($object, $hash, $next, $now);

            return new StoredObject($object, $next, $next, Timestamps::fromDb($head['created_at']), $at);
        });
    }

    public function erase(Iri $iri): void
    {
        $this->transaction(function () use ($iri): void {
            $hash = IriHash::of($iri);
            $this->db->table($this->tables->revisions())->where('iri_hash', $hash)->delete();
            $this->db->table($this->tables->objects())->where('iri_hash', $hash)->delete();
        });
    }

    private function read(Iri $iri): Builder
    {
        return $this->db->table($this->tables->revisions() . ' as r')
            ->join($this->tables->objects() . ' as h', 'h.iri_hash', '=', 'r.iri_hash')
            ->where('r.iri_hash', IriHash::of($iri))
            ->select(['r.iri', 'r.revision', 'r.document', 'r.created_at as revision_created_at', 'h.latest_revision', 'h.created_at as head_created_at']);
    }

    private function insertRevision(LogisticsObject $object, string $hash, int $revision, ?string $at): void
    {
        $this->db->table($this->tables->revisions())->insert([
            'iri_hash' => $hash,
            'iri' => $object->iri->value,
            'revision' => $revision,
            'type' => $object->mostSpecificType(),
            'document' => $object->toJson(pretty: false),
            'created_at' => $at,
        ]);
    }

    /**
     * @param ?array<string, mixed> $row
     */
    private function stored(?array $row): ?StoredObject
    {
        if ($row === null) {
            return null;
        }
        $iri = new Iri(Row::string($row, 'iri'));

        return new StoredObject(
            LogisticsObject::fromJsonLd(Row::string($row, 'document'), $iri),
            Row::int($row, 'revision'),
            Row::int($row, 'latest_revision'),
            Timestamps::fromDb($row['head_created_at']),
            Timestamps::fromDb($row['revision_created_at']),
        );
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db;
    }
}
