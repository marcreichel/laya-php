<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Events;

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Result;

/**
 * Dispatched after each {@see Laya::predict()}, and after each state of a
 * {@see Laya::predictMany()}, whether laya-serve answered or the cache did.
 */
final readonly class PredictionMade
{
    /**
     * @param  list<string>  $questionIds
     * @param  Model|null  $model  the pinned checkpoint; null when laya's router picked one
     * @param  string|null  $routedModel  the checkpoint that answered, e.g. "english"
     * @param  bool  $cached  answered from the cache, without a request
     * @param  float  $durationMs  0 for cache hits; for batches, the duration of the request the state was sent in.
     *                             Identical states in a batch are sent once, and each copy gets an event with that
     *                             request's duration and $cached false.
     * @param  mixed  $state  only when Laya was created with includeState: true, since states may be sensitive
     */
    public function __construct(
        public array $questionIds,
        public ?Model $model,
        public ?string $routedModel,
        public bool $truncated,
        public bool $cached,
        public float $durationMs,
        public int $inputTokens,
        public Result $result,
        public mixed $state = null,
    ) {}
}
