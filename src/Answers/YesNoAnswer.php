<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Answers;

final readonly class YesNoAnswer extends Answer
{
    /**
     * @param  float  $probability  P(yes)
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public float $probability,
        float $confidence,
        float $answerConfidence,
        array $raw,
    ) {
        parent::__construct($confidence, $answerConfidence, $raw);
    }

    /** @param array<mixed> $raw */
    public static function fromArray(string $id, array $raw): self
    {
        return new self(self::float($raw, 'noul'), self::float($raw, 'confidence'), self::float($raw, 'answer_confidence'), $raw);
    }

    public function yes(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }

    public function no(float $threshold = 0.5): bool
    {
        return ! $this->yes($threshold);
    }
}
