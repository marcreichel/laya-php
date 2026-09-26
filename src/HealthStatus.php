<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

final readonly class HealthStatus
{
    /**
     * @param  list<string>  $loaded  checkpoints resident in memory
     * @param  array<string, ?string>  $revisions  checkpoint => commit SHA it was loaded from
     */
    public function __construct(
        public bool $ok,
        public array $loaded,
        public array $revisions,
        public string $device,
    ) {}

    /** @param array<mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $revisions = [];
        foreach ((array) ($raw['revisions'] ?? []) as $model => $sha) {
            $revisions[(string) $model] = is_string($sha) ? $sha : null;
        }

        return new self(
            ($raw['status'] ?? null) === 'ok',
            array_values(array_filter((array) ($raw['loaded'] ?? []), is_string(...))),
            $revisions,
            is_string($raw['device'] ?? null) ? $raw['device'] : 'auto',
        );
    }
}
