<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Answers;

use MarcReichel\Laya\Abstention;
use MarcReichel\Laya\Exceptions\ServerException;

abstract readonly class Answer
{
    /** How laya-serve's abstention gate decided this answer; null when the request set no minConfidence. */
    public ?Abstention $abstention;

    /** The threshold the gate measured this answer against: with a per-bucket minConfidence, its bucket's. */
    public ?float $abstentionThreshold;

    /** The gate abstained: answerConfidence fell below the threshold. laya keeps the answer either way. */
    public bool $lowConfidence;

    /**
     * @param  float  $confidence  laya's per-type confidence (max probability, normalised for the option count)
     * @param  float  $answerConfidence  the calibrated confidence; comparable across question types, so use it for gating.
     *                                   Servers that only follow TypeSafe's OpenAPI spec (e.g. sys1) don't send it; then it falls back to {@see $confidence}
     * @param  array<mixed>  $raw  the answer exactly as laya-serve returned it
     */
    public function __construct(
        public float $confidence,
        public float $answerConfidence,
        public array $raw,
    ) {
        $this->abstention = is_string($raw['abstention'] ?? null) ? Abstention::tryFrom($raw['abstention']) : null;
        $this->abstentionThreshold = is_numeric($raw['abstention_threshold'] ?? null) ? (float) $raw['abstention_threshold'] : null;
        $this->lowConfidence = ($raw['low_confidence'] ?? false) === true;
    }

    /** @param array<mixed> $raw */
    public static function fromArray(string $id, array $raw): self
    {
        return match ($raw['type'] ?? null) {
            'choice' => ChoiceAnswer::fromArray($id, $raw),
            'score' => ScoreAnswer::fromArray($id, $raw),
            'noul' => YesNoAnswer::fromArray($id, $raw),
            default => throw new ServerException(sprintf('Answer "%s" has an unknown type.', $id), 200),
        };
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string|int, float>
     */
    protected static function probabilities(array $raw): array
    {
        $probabilities = [];
        foreach ((array) ($raw['probabilities'] ?? []) as $label => $p) {
            $probabilities[$label] = is_numeric($p) ? (float) $p : 0.0;
        }

        return $probabilities;
    }

    /**
     * answer_confidence, or $confidence when the server didn't send it: TypeSafe's OpenAPI spec has no such field.
     *
     * @param  array<mixed>  $raw
     */
    protected static function answerConfidence(array $raw, float $confidence): float
    {
        return is_numeric($raw['answer_confidence'] ?? null) ? (float) $raw['answer_confidence'] : $confidence;
    }

    /** @param array<mixed> $raw */
    protected static function float(array $raw, string $key): float
    {
        return is_numeric($raw[$key] ?? null) ? (float) $raw[$key] : 0.0;
    }
}
