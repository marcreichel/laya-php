<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Events;

/**
 * Dispatched when the cache fails a prediction can do without: a read that throws or returns an entry
 * that isn't a laya response (the prediction goes to laya-serve), or a write that throws (the result
 * is returned uncached).
 */
final readonly class CacheFailed
{
    /**
     * @param  'get'|'set'  $operation
     * @param  string  $key  the cache key of the prediction
     */
    public function __construct(
        public string $operation,
        public string $key,
        public \Exception $exception,
    ) {}
}
