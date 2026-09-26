<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Attributes;

/**
 * Describes an enum case to the model when the enum is used as a choice in a decision class.
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Describe
{
    public function __construct(public string $description) {}
}
