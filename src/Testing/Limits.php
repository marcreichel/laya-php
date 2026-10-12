<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Testing;

use MarcReichel\Laya\Laya;

/**
 * The request limits {@see Laya::fake()} enforces, so a test fails like production would.
 * The defaults are laya-serve's; null turns a check off. The real client checks nothing: the server decides.
 */
final readonly class Limits
{
    public function __construct(
        public ?int $maxQuestions = 64,
        public ?int $maxChoiceOptions = 100,
        public ?int $maxScoreLevels = 32,
        public ?int $maxTotalOptions = 512,
        public ?int $maxStateLength = 50_000,
        public ?int $maxBatchSize = 64,
    ) {}

    /** A fake that accepts anything. */
    public static function none(): self
    {
        return new self(null, null, null, null, null, null);
    }
}
