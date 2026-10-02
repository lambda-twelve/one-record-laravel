<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Support;

use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * IRIs are compared byte for byte by the SDK, but a MySQL/MariaDB varchar
 * index under a *_ci collation is not, and long partner IRIs blow index
 * limits. Every IRI column that is filtered or joined on therefore has a
 * sibling SHA-256 column, and the indexes live there.
 */
final class IriHash
{
    private function __construct() {}

    public static function of(Iri|string $iri): string
    {
        return hash('sha256', $iri instanceof Iri ? $iri->value : $iri);
    }
}
