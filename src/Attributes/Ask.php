<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Attributes;

use MarcReichel\Laya\Laya;

/**
 * Marks a constructor parameter of a decision class as a question for {@see Laya::decide()}.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Ask
{
    public function __construct(public string $instructions) {}
}
