<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LambdaTwelve\OneRecord\Auth\ClientCredentialsVerifier;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\ClientIdTaken;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\DatabaseClientCredentials;
use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * Registers a partner that may obtain tokens from this host's token endpoint.
 * The secret is generated here and shown once; the database keeps its hash.
 */
final class CreateClientCommand extends Command
{
    protected $signature = 'one-record:client:create
        {agent : The partner\'s logistics agent IRI, put into the logistics_agent_uri claim of its tokens}
        {--name= : A label for operators}
        {--client-id= : Use this client id instead of a generated UUID}
        {--secret= : Use this secret instead of a generated one (prefer generated)}';

    protected $description = 'Register a partner for the ONE Record token endpoint and print its credentials once';

    public function handle(ClientCredentialsVerifier $credentials): int
    {
        if (!$credentials instanceof DatabaseClientCredentials) {
            $this->components->error('Client credentials live in the database; set one-record.storage.driver to "database".');

            return self::FAILURE;
        }
        $agent = $this->argument('agent');
        if (!\is_string($agent)) {
            $this->components->error('The agent IRI is required.');

            return self::INVALID;
        }
        try {
            $iri = new Iri($agent);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $clientId = $this->stringOption('client-id') ?? Str::uuid()->toString();
        $secret = $this->stringOption('secret') ?? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            $credentials->create($clientId, $secret, $iri, $this->stringOption('name'));
        } catch (ClientIdTaken $e) {
            $this->components->error($e->getMessage() . ' Choose another --client-id, or disable and re-create the partner.');

            return self::INVALID;
        }

        $this->components->info('Client registered. The secret is shown once; store it now.');
        $this->components->twoColumnDetail('client_id', $clientId);
        $this->components->twoColumnDetail('client_secret', $secret);
        $this->components->twoColumnDetail('logistics_agent_uri', $iri->value);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
