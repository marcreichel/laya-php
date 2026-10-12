<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use MarcReichel\Laya\Events\CacheFailed;
use MarcReichel\Laya\Events\PredictionMade;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/** A PSR-16 cache whose reads and writes throw $error when told to. */
final class FailingCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    /** @var array<string, null|int|DateInterval> */
    public array $ttls = [];

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
        $this->ttls[$key] = $ttl;

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

/**
 * A Laya whose /health answers with $health (an array, or an int status for a failure) and whose predictions answer LAYA_RESPONSE.
 *
 * @param  array<string, mixed>|int  $health
 * @param  list<RequestInterface>  $sent
 */
function layaVersioned(CacheInterface $cache, array|int $health, array &$sent = [], bool $versionCache = true, ?RecordingDispatcher $events = null, bool $noCache = false): Laya
{
    $client = new class($health, $sent) implements ClientInterface
    {
        /** @param list<RequestInterface> $sent */
        public function __construct(private array|int $health, private array &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;
            if ($request->getMethod() === 'GET') {
                return is_int($this->health) ? new Response($this->health, [], '{}') : new Response(200, [], json_encode($this->health));
            }
            $body = json_decode((string) $request->getBody(), true);
            $results = array_map(fn ($state) => LAYA_RESPONSE, $body['states'] ?? [$body['state']]);

            return new Response(200, [], json_encode(isset($body['states']) ? ['results' => $results] : $results[0]));
        }
    };

    return new Laya('http://laya.local', httpClient: $client, cache: $noCache ? null : $cache, events: $events, versionCache: $versionCache);
}

function ask(Laya $laya): void
{
    $laya->predict('a ticket', ['q' => Question::choice('Q?', ['a', 'b'])]);
}

/** @param list<RequestInterface> $sent */
function healthRequests(array $sent): int
{
    return count(array_filter($sent, fn (RequestInterface $r) => $r->getMethod() === 'GET'));
}

it('keeps the plain keys and never calls /health by default', function () {
    $cache = new FailingCache;
    $sent = [];
    ask(layaVersioned($cache, ['status' => 'ok', 'revisions' => ['m' => 'abc']], $sent, versionCache: false));

    expect(array_keys($cache->items))->toHaveCount(1)
        ->and(array_keys($cache->items)[0])->toMatch('/^laya\.[0-9a-f]{32}$/')
        ->and(healthRequests($sent))->toBe(0);
});

it('does not call /health without a cache', function () {
    $sent = [];
    $laya = layaVersioned(new FailingCache, ['status' => 'ok'], $sent, noCache: true);
    ask($laya);

    expect(healthRequests($sent))->toBe(0);
});

it('puts the revisions into the key, so changed revisions mean new keys and the same ones the same keys', function () {
    $keys = [];
    foreach ([['m' => 'abc', 'n' => null], ['n' => null, 'm' => 'abc'], ['m' => 'def', 'n' => null]] as $revisions) {
        $cache = new FailingCache;
        ask(layaVersioned($cache, ['status' => 'ok', 'revisions' => $revisions]));
        $keys[] = array_keys($cache->items)[1]; // [0] is the cached revision hash
    }

    expect($keys[0])->toMatch('/^laya\.[0-9a-f]{32}\.[0-9a-f]{32}$/')
        ->and($keys[1])->toBe($keys[0])
        ->and($keys[2])->not->toBe($keys[0]);
});

it('asks /health once per instance, and shares the revision hash through the cache', function () {
    $cache = new FailingCache;
    $sent = [];
    $laya = layaVersioned($cache, ['status' => 'ok', 'revisions' => ['m' => 'abc']], $sent);
    ask($laya);
    ask($laya);
    $other = [];
    ask(layaVersioned($cache, 503, $other));

    expect(healthRequests($sent))->toBe(1)
        ->and(healthRequests($other))->toBe(0)
        ->and(array_keys($cache->items))->toHaveCount(2);
});

it('falls back to the plain key when /health fails or reports no revisions', function (array|int $health) {
    $cache = new FailingCache;
    $sent = [];
    $laya = layaVersioned($cache, $health, $sent);
    ask($laya);
    ask($laya);

    expect(array_keys($cache->items)[0])->toMatch('/^laya\.[0-9a-f]{32}$/')
        ->and($cache->items)->toHaveCount(1)
        ->and(healthRequests($sent))->toBe(1);
})->with([
    'health fails' => [503],
    'sys1, no revisions' => [['status' => 'ok']],
]);

it('keeps the revision hash for a minute, under a key of its own', function () {
    $cache = new FailingCache;
    ask(layaVersioned($cache, ['status' => 'ok', 'revisions' => ['m' => 'abc']]));
    $key = array_keys($cache->items)[0];

    expect($key)->toMatch('/^laya\.revisions\.[0-9a-f]{32}$/')
        ->and($cache->ttls[$key])->toBe(60);
});

it('versions the keys of predictMany() too', function () {
    $cache = new FailingCache;
    layaVersioned($cache, ['status' => 'ok', 'revisions' => ['m' => 'abc']])
        ->predictMany(['a', 'b'], ['q' => Question::choice('Q?', ['a', 'b'])]);

    expect(array_keys($cache->items))->toHaveCount(3)
        ->and(array_keys($cache->items)[1])->toMatch('/^laya\.[0-9a-f]{32}\.[0-9a-f]{32}$/');
});

it('reports a cache that fails on the revision hash, and still predicts', function () {
    $events = new RecordingDispatcher;
    $sent = [];
    $cache = new FailingCache(new RuntimeException('down'), new RuntimeException('down'));
    ask(layaVersioned($cache, ['status' => 'ok', 'revisions' => ['m' => 'abc']], $sent, events: $events));

    expect(array_map(fn (CacheFailed $e) => $e->operation, cacheFailures($events)))->toBe(['get', 'set', 'get', 'set'])
        ->and(healthRequests($sent))->toBe(1);
});
