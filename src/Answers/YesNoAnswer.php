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

    /**
     * TypeSafe's OpenAPI spec sends only the probability, so a missing confidence is laya's own: max(P(yes), P(no)).
     * Without a probability either, the answer has no confidence.
     *
     * @param  array<mixed>  $raw
     */
    public static function fromArray(string $id, array $raw): self
    {
        $probability = self::float($raw, 'noul');
        $derived = is_numeric($raw['noul'] ?? null) ? max($probability, 1 - $probability) : 0.0;
        $confidence = is_numeric($raw['confidence'] ?? null) ? (float) $raw['confidence'] : $derived;

        return new self($probability, $confidence, self::answerConfidence($raw, $confidence), $raw);
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
