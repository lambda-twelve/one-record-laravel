<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Storage\Database;

/**
 * The package's table names, behind the configurable prefix.
 */
final readonly class Tables
{
    public function __construct(public string $prefix = 'one_record_') {}

    public function objects(): string
    {
        return $this->prefix . 'logistics_objects';
    }

    public function revisions(): string
    {
        return $this->prefix . 'logistics_object_revisions';
    }

    public function events(): string
    {
        return $this->prefix . 'logistics_events';
    }

    public function actionRequests(): string
    {
        return $this->prefix . 'action_requests';
    }

    public function actionRequestObjects(): string
    {
        return $this->prefix . 'action_request_objects';
    }

    public function subscriptionOffers(): string
    {
        return $this->prefix . 'subscription_offers';
    }

    public function grants(): string
    {
        return $this->prefix . 'grants';
    }

    public function outbox(): string
    {
        return $this->prefix . 'outbox';
    }

    public function clients(): string
    {
        return $this->prefix . 'clients';
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [$this->objects(), $this->revisions(), $this->events(), $this->actionRequests(), $this->actionRequestObjects(), $this->subscriptionOffers(), $this->grants(), $this->outbox(), $this->clients()];
    }
}
