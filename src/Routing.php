<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/** The checkpoint laya-serve's router would pick, from {@see Laya::route()}. */
final readonly class Routing
{
    /**
     * @param  string  $model  the checkpoint, e.g. "english"
     * @param  string  $repo  where its weights come from
     * @param  string  $reason  why the router picked it
     * @param  array<mixed>|null  $diagnostic  the router's stage, reason and source; only with explain
     */
    public function __construct(
        public string $model,
        public string $repo,
        public string $reason,
        public ?array $diagnostic = null,
    ) {}

    /** @param array<mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            is_string($raw['model'] ?? null) ? $raw['model'] : '',
            is_string($raw['repo'] ?? null) ? $raw['repo'] : '',
            is_string($raw['reason'] ?? null) ? $raw['reason'] : '',
            is_array($raw['diagnostic'] ?? null) ? $raw['diagnostic'] : null,
        );
    }
}
