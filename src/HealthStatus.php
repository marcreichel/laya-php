<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

final readonly class HealthStatus
{
    /**
     * @param  list<string>  $loaded  checkpoints resident in memory
     * @param  array<string, ?string>  $revisions  checkpoint => commit SHA it was loaded from
     * @param  ?float  $idleUnloadSeconds  idle window after which laya-serve unloads resident checkpoints; null when idle unload is off
     * @param  ?float  $idleSeconds  time since the last inference request or completion (health probes don't reset it); null when idle unload is off
     */
    public function __construct(
        public bool $ok,
        public array $loaded,
        public array $revisions,
        public string $device,
        public ?float $idleUnloadSeconds = null,
        public ?float $idleSeconds = null,
    ) {}

    /** @param array<mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $revisions = [];
        foreach ((array) ($raw['revisions'] ?? []) as $model => $sha) {
            // PHP normalises array keys either way; the cast is for static analysis.
            $revisions[(string) $model] = is_string($sha) ? $sha : null; // @pest-mutate-ignore: RemoveStringCast
        }

        return new self(
            ($raw['status'] ?? null) === 'ok',
            array_values(array_filter((array) ($raw['loaded'] ?? []), is_string(...))),
            $revisions,
            is_string($raw['device'] ?? null) ? $raw['device'] : 'auto',
            self::seconds($raw['idle_unload_seconds'] ?? null),
            self::seconds($raw['idle_seconds'] ?? null),
        );
    }

    private static function seconds(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
