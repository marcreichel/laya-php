<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Container\Container;
use MarcReichel\Laya\Events\PredictionFailed;
use MarcReichel\Laya\Events\PredictionMade;
use MarcReichel\Laya\Exceptions\AuthenticationException;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\LayaException;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Exceptions\TransportException;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Testing\FakeHttpClient;
use PHPUnit\Framework\Assert;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Client for a laya-serve instance.
 *
 *     $laya = new Laya('http://localhost:8000');
 *     $result = $laya->predict('I was billed twice!', ['churn' => Question::yesNo('Threatens to cancel?')]);
 *     $result->yesNo('churn')->yes();
 */
final class Laya
{
    /** laya-serve refuses batches of more states (MAX_BATCH_STATES). Pest can't cover a constant; the chunking test pins it. */
    private const int BATCH_SIZE = 64; // @pest-mutate-ignore

    /**
     * Sent when no Model is pinned. TypeSafe's spec requires a model name; laya-serve routes by language for any
     * name it doesn't know, and sys1 accepts this alias for whatever it serves.
     *
     * @internal
     */
    public const string AUTO_MODEL = 'jev-latest';

    private readonly string $baseUrl;

    private readonly ClientInterface $http;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param  ClientInterface|null  $httpClient  any PSR-18 client; discovered when omitted
     * @param  CacheInterface|null  $cache  caches predictions by state, questions and model; laya is deterministic
     * @param  int|\DateInterval|null  $cacheTtl  null keeps entries as long as the cache does
     * @param  EventDispatcherInterface|null  $events  receives a PredictionMade or PredictionFailed for every prediction
     * @param  bool  $includeState  put the state on the events; off by default, since states may be sensitive
     */
    public function __construct(
        string $baseUrl = 'http://localhost:8000',
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int|\DateInterval|null $cacheTtl = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly bool $includeState = false,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * A Laya whose answers come from $answers instead of a server, for your tests.
     *
     *     $laya = Laya::fake(['department' => 'billing', 'urgency' => 2, 'churn' => true]);
     *
     * Choice answers take a label (or backed enum case), score answers a level index,
     * yes/no answers a bool or a probability, and null means laya is unsure (zero confidence).
     * An unregistered question throws.
     *
     * Or pass an instance of a decision class, and its properties become the answers:
     *
     *     $laya = Laya::fake(new Triage(Department::Billing, urgency: 2, churn: true));
     *
     * In a Laravel app the fake also replaces the container's Laya, so injected code gets it too.
     *
     * The fake dispatches PredictionMade like a real Laya, to $events (by default, the dispatcher of the
     * container's Laya, so Event::fake() and listeners see them). Its predictions are never cached.
     *
     * @param  array<string, string|int|float|bool|\BackedEnum|null>|object  $answers
     * @param  bool|null  $includeState  null takes the container's setting, or false
     */
    public static function fake(array|object $answers = [], ?EventDispatcherInterface $events = null, ?bool $includeState = null): self
    {
        /** @var array<string, string|int|float|bool|\BackedEnum|null> $answers */
        $answers = is_object($answers) ? get_object_vars($answers) : $answers;
        $bound = class_exists(Container::class) && Container::getInstance()->bound(self::class);
        /** @var self|null $current */
        $current = $bound ? Container::getInstance()->make(self::class) : null;
        $fake = new self(
            'http://laya.test',
            httpClient: new FakeHttpClient($answers),
            events: $events ?? $current?->events,
            includeState: $includeState ?? $current->includeState ?? false,
        );

        if ($bound) {
            Container::getInstance()->instance(self::class, $fake);
        }

        return $fake;
    }

    /**
     * Ask laya one or more typed questions about $state.
     *
     * @param  string|array<mixed>|\JsonSerializable  $state  text, a JSON document, or a list of conversation turns
     * @param  array<string, Question>  $questions  question id => question
     * @param  Model|null  $model  pin a checkpoint; null lets laya's router pick by language
     * @param  int|null  $maxLen  token budget for the state; longer states are cut off (laya-serve >= 0.3.21)
     * @param  int|null  $headMaxLen  token budget for each question and its options (laya-serve >= 0.3.21)
     */
    public function predict(string|array|\JsonSerializable $state, array $questions, ?Model $model = null, ?int $maxLen = null, ?int $headMaxLen = null): Result
    {
        $json = self::json($this->body(['state' => $state], self::wire($questions), $model, $maxLen, $headMaxLen));
        $key = self::cacheKey($json);
        $cached = $this->cache?->get($key);
        if (is_array($cached)) {
            $result = Result::fromArray($cached);
            $this->events?->dispatch($this->made($state, $questions, $model, $result, true, 0.0));

            return $result;
        }

        $start = hrtime(true);
        try {
            $raw = $this->send($this->post('/v1/systemone', $json));
            $result = Result::fromArray($raw);
        } catch (LayaException $e) {
            $this->failed($e, $state, $questions, $model, $start);

            throw $e;
        }
        $this->cache?->set($key, $raw, $this->cacheTtl);
        $this->events?->dispatch($this->made($state, $questions, $model, $result, false, self::since($start)));

        return $result;
    }

    /**
     * Ask the same questions about many states, answered in shared forward passes.
     *
     *     $results = $laya->predictMany($tickets->pluck('body', 'id')->all(), $questions);
     *     $results[42]->choice('department');
     *
     * Results keep the keys of $states. Cached states aren't sent again, and the rest go out
     * in requests of at most 64 states.
     *
     * @experimental needs laya-serve >= 0.3.22 (>= 0.3.23 for token budgets), and may change in a minor release
     *
     * @template K of array-key
     *
     * @param  array<K, string|array<mixed>|\JsonSerializable>  $states
     * @param  array<string, Question>  $questions
     * @param  int|null  $maxLen  token budget for each state (laya-serve >= 0.3.23)
     * @param  int|null  $headMaxLen  token budget for each question and its options (laya-serve >= 0.3.23)
     * @return array<K, Result>
     */
    public function predictMany(array $states, array $questions, ?Model $model = null, ?int $maxLen = null, ?int $headMaxLen = null): array
    {
        $wire = self::wire($questions);
        $results = [];
        $misses = [];
        foreach ($states as $id => $state) {
            $key = self::cacheKey(self::json($this->body(['state' => $state], $wire, $model, $maxLen, $headMaxLen)));
            $cached = $this->cache?->get($key);
            // Placeholders keep the input order for the answers filled in below.
            $results[$id] = null;
            if (is_array($cached)) {
                $results[$id] = Result::fromArray($cached);
                $this->events?->dispatch($this->made($state, $questions, $model, $results[$id], true, 0.0));
            } else {
                $misses[$id] = $key;
            }
        }

        foreach (array_chunk($misses, self::BATCH_SIZE, preserve_keys: true) as $chunk) {
            $sent = array_intersect_key($states, $chunk);
            // The batch endpoint takes the same controls as a single prediction and applies them to every state.
            $body = $this->body(['states' => array_values($sent)], $wire, $model, $maxLen, $headMaxLen);
            $start = hrtime(true);
            try {
                $answers = $this->sendBatch($body, count($chunk));
                $parsed = array_map(Result::fromArray(...), $answers);
            } catch (LayaException $e) {
                $this->failed($e, $sent, $questions, $model, $start);

                throw $e;
            }
            $duration = self::since($start);
            foreach (array_keys($chunk) as $i => $id) {
                $results[$id] = $parsed[$i];
                $this->cache?->set($chunk[$id], $answers[$i], $this->cacheTtl);
                $this->events?->dispatch($this->made($states[$id], $questions, $model, $parsed[$i], false, $duration));
            }
        }

        /** @var array<K, Result> $results */
        return $results;
    }

    /**
     * Ask the questions a decision class declares and get an instance of it back.
     *
     *     final class Triage {
     *         public function __construct(
     *             #[Ask('Which department should handle this?')] public Department $department,
     *             #[Ask('Does the user threaten to cancel?')] public bool $churn,
     *         ) {}
     *     }
     *
     *     $triage = $laya->decide($ticket, Triage::class);
     *
     * @template T of object
     *
     * @param  string|array<mixed>|\JsonSerializable  $state
     * @param  class-string<T>  $class
     * @return T
     */
    public function decide(string|array|\JsonSerializable $state, string $class, ?Model $model = null, ?int $maxLen = null, ?int $headMaxLen = null): object
    {
        return DecisionMapper::hydrate($class, $this->predict($state, DecisionMapper::questions($class), $model, $maxLen, $headMaxLen));
    }

    /**
     * decide() for many states at once, through predictMany(). Decisions keep the keys of $states.
     *
     * @experimental needs laya-serve >= 0.3.22 (>= 0.3.23 for token budgets), and may change in a minor release
     *
     * @template T of object
     * @template K of array-key
     *
     * @param  array<K, string|array<mixed>|\JsonSerializable>  $states
     * @param  class-string<T>  $class
     * @return array<K, T>
     */
    public function decideMany(array $states, string $class, ?Model $model = null, ?int $maxLen = null, ?int $headMaxLen = null): array
    {
        return array_map(fn (Result $result) => DecisionMapper::hydrate($class, $result), $this->predictMany($states, DecisionMapper::questions($class), $model, $maxLen, $headMaxLen));
    }

    public function health(): HealthStatus
    {
        return HealthStatus::fromArray($this->send($this->request('GET', '/health')));
    }

    /**
     * Assert at least one prediction was made (matching $callback, if given). Faked clients only.
     *
     * @param  (callable(mixed $state, array<array-key, mixed> $questions, ?string $model): bool)|null  $callback
     */
    public function assertPredicted(?callable $callback = null): void
    {
        $matching = array_filter($this->fakeClient()->requests, fn (array $r) => $callback === null || $callback($r['state'], $r['questions'], $r['model']));
        self::assert($matching !== [], 'Expected a matching laya prediction, but none was made.');
    }

    /**
     * Assert $class was decided at least once (on a state matching $callback, if given). Faked clients only.
     *
     * @param  class-string  $class
     * @param  (callable(mixed $state, ?string $model): bool)|null  $callback
     */
    public function assertDecided(string $class, ?callable $callback = null): void
    {
        $questions = json_decode(json_encode(array_map(fn (Question $q) => $q->toArray(), DecisionMapper::questions($class)), JSON_THROW_ON_ERROR), true);
        $matching = array_filter($this->fakeClient()->requests, fn (array $r) => $r['questions'] === $questions && ($callback === null || $callback($r['state'], $r['model'])));
        self::assert($matching !== [], sprintf('Expected %s to be decided, but it wasn\'t.', $class));
    }

    public function assertPredictedCount(int $count): void
    {
        $actual = count($this->fakeClient()->requests);
        self::assert($actual === $count, sprintf('Expected %d laya prediction(s), but %d were made.', $count, $actual));
    }

    public function assertNothingPredicted(): void
    {
        $this->assertPredictedCount(0);
    }

    /**
     * Sends one batch request and returns the $count answers, one per state.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<mixed>>
     */
    private function sendBatch(array $body, int $count): array
    {
        try {
            $raw = $this->send($this->post('/v1/systemone/batch', self::json($body)));
        } catch (ServerException $e) {
            throw $e->status === 404 ? new ServerException('laya-serve has no batch endpoint; predictMany() needs laya-serve 0.3.22 or later.', 404, $e) : $e;
        }
        $answers = $raw['results'] ?? null;
        if (! is_array($answers) || ! array_is_list($answers) || count($answers) !== $count) {
            throw new ServerException('The laya-serve batch response doesn\'t have one result per state.', 200);
        }

        return array_map(fn (mixed $answer) => is_array($answer) ? $answer : [], $answers);
    }

    /**
     * Only called when there's a dispatcher: `?->` skips building the event without one.
     *
     * @param  array<string, Question>  $questions
     */
    private function made(mixed $state, array $questions, ?Model $model, Result $result, bool $cached, float $durationMs): PredictionMade
    {
        return new PredictionMade(
            array_keys($questions),
            $model,
            $result->routedModel,
            $result->truncated,
            $cached,
            $durationMs,
            $result->inputTokens,
            $result,
            $this->includeState ? $state : null,
        );
    }

    /**
     * Dispatches a PredictionFailed. A listener that throws mustn't replace $e, the error callers handle, so its exception is dropped.
     *
     * @param  array<string, Question>  $questions
     */
    private function failed(LayaException $e, mixed $state, array $questions, ?Model $model, int|float $start): void
    {
        try {
            $this->events?->dispatch(new PredictionFailed(array_keys($questions), $model, $e, self::since($start), $this->includeState ? $state : null));
        } catch (\Exception) {
            // Only exceptions: an Error is a bug, and should surface.
        }
    }

    private static function since(int|float $start): float
    {
        return (hrtime(true) - $start) / 1e6; // @pest-mutate-ignore nanoseconds to milliseconds; no test can pin a duration
    }

    /** @param array<string, Question> $questions */
    private static function wire(array $questions): object
    {
        $wire = [];
        foreach ($questions as $id => $question) {
            if (! is_string($id)) {
                throw new InvalidQuestionException(sprintf('Question ids must be strings, got %s. Pass questions as [\'id\' => Question::...].', get_debug_type($id)));
            }
            if (! $question instanceof Question) {
                throw new InvalidQuestionException(sprintf('Question "%s" must be a %s, got %s.', $id, Question::class, get_debug_type($question)));
            }
            $wire[$id] = $question->toArray();
        }

        return (object) $wire;
    }

    /**
     * @param  array{state: mixed}|array{states: list<mixed>}  $states  one state, or a batch's states
     * @return array<string, mixed>
     */
    private function body(array $states, object $wire, ?Model $model, ?int $maxLen = null, ?int $headMaxLen = null): array
    {
        $body = $states + ['questions' => $wire, 'model' => $model->value ?? self::AUTO_MODEL];
        // laya-serve validates both (positive, <= LAYA_MAX_TOKEN_BUDGET) and answers 422.
        if ($maxLen !== null) {
            $body['max_len'] = $maxLen;
        }
        if ($headMaxLen !== null) {
            $body['head_max_len'] = $headMaxLen;
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    // The key ignores the checkpoint revision; set a cacheTtl or clear the cache after upgrading laya.
    private static function cacheKey(string $json): string
    {
        return 'laya.'.hash('xxh128', $json);
    }

    private function post(string $path, string $json): RequestInterface
    {
        return $this->request('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($json));
    }

    private function request(string $method, string $path): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $this->baseUrl.$path)
            ->withHeader('Accept', 'application/json');

        return $this->apiKey === null ? $request : $request->withHeader('Authorization', 'Bearer '.$this->apiKey);
    }

    /** @return array<mixed> */
    private function send(RequestInterface $request): array
    {
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(sprintf('Could not reach laya-serve at %s: %s', $this->baseUrl, $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        if ($status >= 200 && $status < 300) {
            if (! is_array($body)) {
                throw new ServerException('laya-serve returned a response that is not a JSON object.', $status);
            }

            return $body;
        }

        // FastAPI errors are {"detail": "..."}; validation errors from FastAPI itself carry a list.
        $detail = is_array($body) ? ($body['detail'] ?? null) : null;
        $message = is_string($detail) ? $detail : ($detail !== null ? json_encode($detail) : $response->getReasonPhrase());
        $message = sprintf('laya-serve: %s (HTTP %d)', $message ?: 'error', $status);

        throw match ($status) {
            401 => new AuthenticationException($message, $status),
            503 => new ServerBusyException($message, $status),
            400, 413, 422 => new ValidationException($message, $status),
            default => new ServerException($message, $status),
        };
    }

    private function fakeClient(): FakeHttpClient
    {
        return $this->http instanceof FakeHttpClient
            ? $this->http
            : throw new \LogicException('Assertions are only available on a client created with Laya::fake().');
    }

    private static function assert(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }

        // Unreachable in this suite, which always has PHPUnit; it's for callers without it.
        // @codeCoverageIgnoreStart
        if (! $condition) { // @pest-mutate-ignore
            throw new \AssertionError($message);
        }
        // @codeCoverageIgnoreEnd
    }
}
