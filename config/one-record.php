<?php

declare(strict_types=1);

use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;

/*
 * Every key here maps onto a constructor argument of the SDK (ServerConfig,
 * the authenticator, the token endpoint, ...). The package translates; it does
 * not keep a configuration model of its own.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Server identity
    |--------------------------------------------------------------------------
    |
    | base_url is scheme and host only (https://1r.example.com); base_path is
    | the prefix the ONE Record endpoints are mounted under, and it doubles as
    | the route prefix so the two can never diverge. data_holder is the IRI of
    | the organisation that owns the data (also a logistics object this server
    | serves); a bare id is resolved under {base_url}{base_path}/logistics-objects/.
    | All IRIs the server mints embed base_url and base_path: changing either
    | later orphans stored data.
    |
    */
    'server' => [
        'base_url' => env('ONE_RECORD_BASE_URL', env('APP_URL', 'http://localhost')),
        'base_path' => env('ONE_RECORD_BASE_PATH', '/one-record'),
        'data_holder' => env('ONE_RECORD_DATA_HOLDER'),
        'data_holder_type' => env('ONE_RECORD_DATA_HOLDER_TYPE', Cargo::Company),
        // null = every API / data model version the SDK knows; otherwise a list such as ['2.3.0'] or '2.2.0,2.3.0'.
        'api_versions' => env('ONE_RECORD_API_VERSIONS'),
        'data_model_versions' => env('ONE_RECORD_DATA_MODEL_VERSIONS'),
        'languages' => ['en-US'],
        'max_body_bytes' => 1_048_576,
        'embedded_depth' => 3,
        'bulk_logistics_events' => (bool) env('ONE_RECORD_BULK_EVENTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | With register = true the package mounts the server (and, when enabled,
    | the token and JWKS endpoints) itself. Set it to false and call
    | OneRecord::routes() / OneRecord::tokenRoutes() from your own routes file
    | to control middleware, domain and grouping yourself.
    |
    */
    'routes' => [
        'register' => true,
        'middleware' => [],
        'domain' => null,
        'name' => 'one-record.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | database: the package's tables on the given connection (null = default).
    | array:    the SDK's in-memory stores, one set per process; only for tests.
    | With the database driver the SDK's unit of work runs on that connection,
    | so every operation (a mutating request, a DataHolder call) is one
    | transaction. transactions additionally wraps each whole request in one:
    | reads see one snapshot, and a 5xx answer rolls everything back.
    |
    */
    'storage' => [
        'driver' => env('ONE_RECORD_STORAGE', 'database'),
        'connection' => env('ONE_RECORD_DB_CONNECTION'),
        'table_prefix' => 'one_record_',
        'migrations' => true,
        'transactions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Access policy
    |--------------------------------------------------------------------------
    |
    | internal_agents act for the host and may do everything, including
    | deciding action requests; the data holder is always internal. denial is
    | what a refused partner sees: forbid (403) or hide (404).
    |
    */
    'policy' => [
        'denial' => env('ONE_RECORD_DENIAL', 'forbid'),
        'internal_agents' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | jwt verifies partners' RS256 bearer tokens (the spec's model). issuers
    | maps each trusted issuer to either static public keys or a JWKS URL:
    |   'https://auth.partner.example' => ['keys' => [$pem]]           // or ['kid' => $pem]
    |   'https://auth.other.example'   => ['jwks' => true]             // {issuer}/.well-known/jwks.json
    |   'https://auth.third.example'   => ['jwks' => 'https://.../jwks.json']
    | custom means the application binds LambdaTwelve\OneRecord\Server\Spi\Authenticator itself.
    |
    | The token endpoint issues tokens to this host's own partners from the
    | one_record_clients table; the JWKS route publishes the signing key.
    |
    */
    'auth' => [
        'driver' => env('ONE_RECORD_AUTH', 'jwt'),
        'audience' => env('ONE_RECORD_AUDIENCE'),
        'leeway' => 30,
        'jwks_ttl' => 3600,
        'issuers' => [],
        'token_endpoint' => [
            'enabled' => (bool) env('ONE_RECORD_TOKEN_ENDPOINT', false),
            'path' => '/oauth/token',
            'middleware' => ['throttle:60,1'],
            'issuer' => env('ONE_RECORD_ISSUER'),
            'private_key' => env('ONE_RECORD_PRIVATE_KEY'),
            'key_id' => env('ONE_RECORD_KEY_ID'),
            'ttl' => 3600,
            'audience' => env('ONE_RECORD_AUDIENCE'),
        ],
        'jwks' => [
            'enabled' => (bool) env('ONE_RECORD_JWKS', false),
            'path' => '/.well-known/jwks.json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Framework services the SDK consumes
    |--------------------------------------------------------------------------
    */
    'cache' => ['store' => env('ONE_RECORD_CACHE_STORE')],   // PSR-16 for JWKS documents, tokens, server information
    'log' => ['channel' => env('ONE_RECORD_LOG_CHANNEL')],   // PSR-3 channel; null = the default channel
    'http' => ['timeout' => 10, 'connect_timeout' => 5],     // Guzzle options for the PSR-18 client

    /*
    |--------------------------------------------------------------------------
    | Notification outbox
    |--------------------------------------------------------------------------
    |
    | dispatch = queue pushes a delivery job after the enqueuing transaction
    | commits; none leaves delivery to `one-record:outbox:deliver` (cron).
    | A worker leases a row for lease_seconds; delivery is at least once, so
    | recipients deduplicate on the notification id (the Idempotency-Key).
    |
    */
    'outbox' => [
        'dispatch' => env('ONE_RECORD_OUTBOX_DISPATCH', 'queue'),
        'connection' => env('ONE_RECORD_OUTBOX_QUEUE_CONNECTION'),
        'queue' => env('ONE_RECORD_OUTBOX_QUEUE'),
        'max_attempts' => 10,
        'lease_seconds' => 120,
    ],

    // Seed for deterministic UUIDv5 object IRIs when publishing local graphs (null = random UUIDv4).
    'iri' => ['seed' => env('ONE_RECORD_IRI_SEED')],
];
