<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Answers;

final readonly class ScoreAnswer extends Answer
{
    /**
     * @param  float  $score  the expected level, e.g. 1.74 between "soon" (1) and "blocking" (2)
     * @param  list<string>  $legend  level descriptions, index 0 first
     * @param  list<float>  $probabilities  probability per level, index 0 first
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public float $score,
        public array $legend,
        public array $probabilities,
        float $confidence,
        float $answerConfidence,
        array $raw,
    ) {
        parent::__construct($confidence, $answerConfidence, $raw);
    }

    /** @param array<mixed> $raw */
    public static function fromArray(string $id, array $raw): self
    {
        $confidence = self::float($raw, 'confidence');

        return new self(
            self::float($raw, 'score'),
            // laya keys both by level index ("0", "1", ...) in order.
            array_values(array_filter((array) ($raw['legend'] ?? []), is_string(...))),
            array_values(self::probabilities($raw)),
            $confidence,
            self::answerConfidence($raw, $confidence),
            $raw,
        );
    }

    /** The most likely level (argmax), as opposed to the expected {@see $score}. */
    public function level(): int
    {
        if ($this->probabilities === []) {
            return (int) round($this->score);
        }

        // A list's keys are ints; the cast is for static analysis.
        return (int) array_search(max($this->probabilities), $this->probabilities, true); // @pest-mutate-ignore: RemoveIntegerCast
    }

    /** The description of the most likely level. */
    public function label(): ?string
    {
        return $this->legend[$this->level()] ?? null;
    }
}
