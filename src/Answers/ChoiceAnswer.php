<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Answers;

use MarcReichel\Laya\Exceptions\ServerException;

final readonly class ChoiceAnswer extends Answer
{
    /**
     * @param  string|int  $choice  the most likely option label
     * @param  array<string|int, float>  $probabilities  label => probability
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public string|int $choice,
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
        $choice = $raw['choice'] ?? null;
        if (! is_string($choice) && ! is_int($choice)) {
            throw new ServerException(sprintf('Choice answer "%s" has no choice.', $id), 200);
        }

        return new self($choice, self::probabilities($raw), self::float($raw, 'confidence'), self::float($raw, 'answer_confidence'), $raw);
    }

    /** Whether the chosen label is $label. Compares loosely, since JSON turns numeric labels into strings. */
    public function is(string|int $label): bool
    {
        return (string) $this->choice === (string) $label;
    }
}
