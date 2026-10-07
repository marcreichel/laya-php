<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Hands Laya's events to the app's dispatcher, resolved on each event so Event::fake() applies.
 */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private Container $app) {}

    public function dispatch(object $event): object
    {
        $this->app->make(Dispatcher::class)->dispatch($event);

        return $event;
    }
}
