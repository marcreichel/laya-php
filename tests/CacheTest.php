<?php

declare(strict_types=1);

use MarcReichel\Laya\Events\CacheFailed;
use MarcReichel\Laya\Events\PredictionMade;
use MarcReichel\Laya\Exceptions\ServerException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

/** A PSR-16 cache whose reads and writes throw $error when told to. */
final class FailingCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function __construct(private ?Throwable $getError = null, private ?Throwable $setError = null) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->getError !== null) {
            throw $this->getError;
        }

        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if ($this->setError !== null) {
            throw $this->setError;
        }
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }
}

/** @return list<CacheFailed> */
function cacheFailures(RecordingDispatcher $events): array
{
    return array_values(array_filter($events->events, fn (object $event) => $event instanceof CacheFailed));
}

it('asks the server when the cache cannot be read', function () {
    $sent = [];
    $events = new RecordingDispatcher;
    $down = new RuntimeException('Redis is down');
    $cache = new FailingCache(getError: $down);

    $result = layaBatching($sent, $cache, $events)->predict('Billed twice', questions());

    [$failed, $made] = $events->events;
    expect($result->routedModel)->toBe('Billed twice')
        ->and($sent)->toHaveCount(1)
        ->and($failed)->toBeInstanceOf(CacheFailed::class)
        ->and($failed->operation)->toBe('get')
        ->and($failed->key)->toMatch('/^laya\.[0-9a-f]{32}$/')
        ->and($failed->exception)->toBe($down)
        ->and($made)->toBeInstanceOf(PredictionMade::class)
        ->and($made->cached)->toBeFalse()
        ->and($cache->items)->toHaveKey($failed->key);
});

it('returns the answer when the cache cannot be written', function () {
    $sent = [];
    $events = new RecordingDispatcher;
    $down = new RuntimeException('Redis is down');

    $result = layaBatching($sent, new FailingCache(setError: $down), $events)->predict('Billed twice', questions());

    [$failed, $made] = $events->events;
    expect($result->routedModel)->toBe('Billed twice')
        ->and($failed)->toBeInstanceOf(CacheFailed::class)
        ->and($failed->operation)->toBe('set')
        ->and($failed->key)->toMatch('/^laya\.[0-9a-f]{32}$/')
        ->and($failed->exception)->toBe($down)
        ->and($made)->toBeInstanceOf(PredictionMade::class)
        ->and($made->result)->toBe($result);
});

it('asks the server instead of reading an entry that is not a laya response, and overwrites it', function () {
    $sent = [];
    $events = new RecordingDispatcher;
    $cache = new FailingCache;
    $laya = layaBatching($sent, $cache, $events);
    $laya->predict('Billed twice', questions());
    $key = array_key_first($cache->items);
    $cache->items[$key] = ['written' => 'by something else'];
    $events->events = [];

    $result = $laya->predict('Billed twice', questions());

    [$failed, $made] = $events->events;
    expect($result->routedModel)->toBe('Billed twice')
        ->and($sent)->toHaveCount(2)
        ->and($failed->operation)->toBe('get')
        ->and($failed->key)->toBe($key)
        ->and($failed->exception)->toBeInstanceOf(ServerException::class)
        ->and($made->cached)->toBeFalse()
        ->and($cache->items[$key])->toBe($result->raw)
        ->and($laya->predict('Billed twice', questions()))->toEqual($result)
        ->and($sent)->toHaveCount(2);
});

it('treats an entry that is not an array as a miss, without a failure', function () {
    $sent = [];
    $events = new RecordingDispatcher;
    $cache = new FailingCache;
    $laya = layaBatching($sent, $cache, $events);
    $laya->predict('Billed twice', questions());
    $cache->items[array_key_first($cache->items)] = 'garbage';

    $laya->predict('Billed twice', questions());

    expect($sent)->toHaveCount(2)
        ->and(cacheFailures($events))->toBe([]);
});

it('sends every state of a batch when the cache cannot be read', function () {
    $sent = [];
    $events = new RecordingDispatcher;

    $results = layaBatching($sent, new FailingCache(getError: new RuntimeException('Redis is down')), $events)->predictMany(['a' => 'one', 'b' => 'two'], questions());

    expect(array_map(fn ($r) => $r->routedModel, $results))->toBe(['a' => 'one', 'b' => 'two'])
        ->and($sent)->toHaveCount(1)
        ->and(json_decode((string) $sent[0]->getBody(), true)['states'])->toBe(['one', 'two'])
        ->and(array_map(fn (CacheFailed $e) => $e->operation, cacheFailures($events)))->toBe(['get', 'get']);
});

it('returns every answer of a batch when the cache cannot be written', function () {
    $sent = [];
    $events = new RecordingDispatcher;

    $results = layaBatching($sent, new FailingCache(setError: new RuntimeException('Redis is down')), $events)->predictMany(['a' => 'one', 'b' => 'two'], questions());

    $failures = cacheFailures($events);
    expect(array_map(fn ($r) => $r->routedModel, $results))->toBe(['a' => 'one', 'b' => 'two'])
        ->and(array_map(fn (CacheFailed $e) => $e->operation, $failures))->toBe(['set', 'set'])
        ->and($failures[0]->key)->not->toBe($failures[1]->key)
        ->and(array_filter($events->events, fn (object $e) => $e instanceof PredictionMade))->toHaveCount(2);
});

it('sends the states of a batch whose entries are not laya responses, and overwrites them', function () {
    $sent = [];
    $events = new RecordingDispatcher;
    $cache = new FailingCache;
    $laya = layaBatching($sent, $cache, $events);
    $laya->predictMany(['a' => 'one', 'b' => 'two'], questions());
    $keys = array_keys($cache->items);
    $cache->items[$keys[1]] = ['answers' => 'not a list'];
    $events->events = [];

    $results = $laya->predictMany(['a' => 'one', 'b' => 'two'], questions());

    $failures = cacheFailures($events);
    expect(array_map(fn ($r) => $r->routedModel, $results))->toBe(['a' => 'one', 'b' => 'two'])
        ->and($sent)->toHaveCount(2)
        ->and(json_decode((string) $sent[1]->getBody(), true)['states'])->toBe(['two'])
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]->operation)->toBe('get')
        ->and($failures[0]->key)->toBe($keys[1])
        ->and($cache->items[$keys[1]])->toBe($results['b']->raw);
});

it('works without a dispatcher', function () {
    $results = layaBatching(cache: new FailingCache(new RuntimeException('down'), new RuntimeException('down')))->predictMany(['a' => 'one'], questions());

    expect($results['a']->routedModel)->toBe('one');
});

it('drops the exception of a CacheFailed listener', function () {
    $events = new class implements EventDispatcherInterface
    {
        public function dispatch(object $event): object
        {
            return $event instanceof CacheFailed ? throw new RuntimeException('listener broke') : $event;
        }
    };

    $result = layaBatching(cache: new FailingCache(new RuntimeException('down'), new RuntimeException('down')), events: $events)->predict('Billed twice', questions());

    expect($result->routedModel)->toBe('Billed twice');
});

it('lets an Error from the cache surface', function () {
    expect(fn () => layaBatching(cache: new FailingCache(getError: new TypeError('a bug')))->predict('x', questions()))->toThrow(TypeError::class, 'a bug')
        ->and(fn () => layaBatching(cache: new FailingCache(setError: new TypeError('a bug')))->predict('x', questions()))->toThrow(TypeError::class, 'a bug');
});
