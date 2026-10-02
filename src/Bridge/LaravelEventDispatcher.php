<?php

declare(strict_types=1);

namespace LambdaTwelve\OneRecord\Laravel\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Hands the SDK's PSR-14 events to Laravel's dispatcher, so applications
 * listen for the SDK's event classes exactly like their own events
 * (`Event::listen(LogisticsObjectCreated::class, ...)`, queued listeners,
 * subscribers). Dispatch is synchronous, as PSR-14 requires.
 */
final class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly Dispatcher $events) {}

    public function dispatch(object $event): object
    {
        $this->events->dispatch($event);

        return $event;
    }
}
