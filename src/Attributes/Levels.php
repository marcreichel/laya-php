<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Attributes;

/**
 * Turns an int parameter of a decision class into a score question. The value is the most likely level index.
 *
 *     #[Ask('How urgent is this?'), Levels('not urgent', 'soon', 'blocking')]
 *     public int $urgency, // 0, 1 or 2
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Levels
{
    /** @var list<string> */
    public array $levels;

    public function __construct(string ...$levels)
    {
        $this->levels = array_values($levels);
    }
}
