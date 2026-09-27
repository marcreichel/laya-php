<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Attributes;

use MarcReichel\Laya\Laya;

/**
 * Marks a constructor parameter of a decision class as a question for {@see Laya::decide()}.
 *
 *     #[Ask('Does the user threaten to cancel?', yes: 'explicitly says they will leave', threshold: 0.3)]
 *     public bool $churn,
 *
 *     #[Ask('Which department should handle this?', minConfidence: 0.7)]
 *     public ?Department $department, // null when the calibrated confidence is below 0.7
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)] // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
final readonly class Ask
{
    /**
     * @param  string|null  $yes  bool only: what "yes" means
     * @param  string|null  $no  bool only: what "no" means
     * @param  float  $threshold  bool only: the P(yes) from which the value is true
     * @param  float|null  $minConfidence  below this answerConfidence the value is null; needs a nullable parameter
     */
    public function __construct(
        public string $instructions,
        public ?string $yes = null,
        public ?string $no = null,
        public float $threshold = 0.5,
        public ?float $minConfidence = null,
    ) {}
}
