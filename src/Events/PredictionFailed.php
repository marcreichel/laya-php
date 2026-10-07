<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Events;

use MarcReichel\Laya\Exceptions\LayaException;
use MarcReichel\Laya\Model;

/**
 * Dispatched when a request to laya-serve fails, right before $exception is thrown.
 * A failed batch request dispatches one event for all the states it carried.
 */
final readonly class PredictionFailed
{
    /**
     * @param  list<string>  $questionIds
     * @param  Model|null  $model  the pinned checkpoint; null when laya's router would have picked one
     * @param  mixed  $state  only when Laya was created with includeState: true; for a batch, the states of the failed request, keyed as given
     */
    public function __construct(
        public array $questionIds,
        public ?Model $model,
        public LayaException $exception,
        public float $durationMs,
        public mixed $state = null,
    ) {}
}
