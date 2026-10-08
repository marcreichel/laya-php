<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Attributes;

/**
 * Turns an array parameter of a decision class into one yes/no question per case of a backed enum.
 * `{case}` in the instructions becomes the case's #[Describe] text, or its value. The value lists the
 * cases whose P(yes) reaches the threshold, in declaration order.
 *
 *     #[Ask('Does this message mention {case}?', threshold: 0.3), Of(Topic::class)]
 *     public array $topics, // [Topic::Billing, Topic::Account]
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)] // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
final readonly class Of
{
    /** @param class-string $enum */
    public function __construct(public string $enum) {}
}
