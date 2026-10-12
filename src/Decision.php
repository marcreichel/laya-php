<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/**
 * A hydrated decision together with the {@see Result} it was read from.
 *
 *     $decision = $laya->decideWithResult($ticket, Triage::class);
 *     $decision->value;                                          // Triage
 *     $decision->result->choice('department')->probabilities;
 *
 * @template T of object
 */
final readonly class Decision
{
    /**
     * @param  T  $value
     */
    public function __construct(
        public object $value,
        public Result $result,
    ) {}
}
