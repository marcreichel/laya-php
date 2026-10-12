<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/**
 * How laya collapsed one question's options to fit its token budget.
 */
final readonly class CollapsedOptions implements \JsonSerializable
{
    /**
     * @param  int  $total  options the question has
     * @param  int  $distinct  options laya told apart after collapsing
     * @param  int  $tokensPerOption  tokens each option got
     */
    public function __construct(
        public int $total,
        public int $distinct,
        public int $tokensPerOption,
    ) {}

    /** null unless $raw is a complete report */
    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $total = $raw['total'] ?? null;
        $distinct = $raw['distinct'] ?? null;
        $tokens = $raw['tokens_per_option'] ?? null;

        return is_int($total) && is_int($distinct) && is_int($tokens) ? new self($total, $distinct, $tokens) : null;
    }

    /** @return array{total: int, distinct: int, tokens_per_option: int} */
    public function jsonSerialize(): array
    {
        return ['total' => $this->total, 'distinct' => $this->distinct, 'tokens_per_option' => $this->tokensPerOption];
    }
}
