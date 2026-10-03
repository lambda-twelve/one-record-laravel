<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Model\LogisticsObject;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

/**
 * Small factories for the SDK documents the store tests persist.
 */
final class Documents
{
    public const string BASE = 'https://1r.test/one-record';
    public const string PARTNER = 'https://partner.example/logistics-objects/partner';
    public const string OTHER = 'https://other.example/logistics-objects/other';

    private function __construct() {}

    public static function iri(string $id): Iri
    {
        return new Iri(self::BASE . '/logistics-objects/' . $id);
    }

    public static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    public static function piece(string $id, string $description = 'Perishables'): LogisticsObject
    {
        return ObjectBuilder::of(Cargo::Piece)->set(Cargo::goodsDescription, $description)->build(self::iri($id));
    }

    /**
     * An event with whichever of eventDate / creationDate / eventCode the test wants.
     *
     * @param Iri|string|null $code a code-list IRI, or a plain string code
     */
    public static function event(string $objectId, string $eventId, string $created, ?string $eventDate = null, ?string $creationDate = null, Iri|string|null $code = null): LogisticsEvent
    {
        $object = self::iri($objectId);
        $iri = new Iri($object->value . '/logistics-events/' . $eventId);
        // The SDK's checked event builder (ObjectBuilder::ofEvent) insists on an eventDate; the store tests
        // need events without one, so this writes the triples unchecked.
        $builder = ObjectBuilder::unchecked([Cargo::LogisticsEvent])->set(Cargo::eventName, 'Status ' . $eventId);
        if ($eventDate !== null) {
            $builder->set(Cargo::eventDate, self::at($eventDate));
        }
        if ($creationDate !== null) {
            $builder->set(Cargo::creationDate, self::at($creationDate));
        }
        if ($code !== null) {
            $builder->set(Cargo::eventCode, $code);
        }

        return new LogisticsEvent($iri, $object, $builder->buildGraph($iri), self::at($created));
    }

    public static function statusCode(string $code): Iri
    {
        return new Iri('https://onerecord.iata.org/ns/code-lists/StatusCode#' . $code);
    }
}
