<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use MarcReichel\Laya\Exceptions\AuthenticationException;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Exceptions\TransportException;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Testing\FakeHttpClient;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Client for a laya-serve instance.
 *
 *     $laya = new Laya('http://localhost:8000');
 *     $result = $laya->predict('I was billed twice!', ['churn' => Question::yesNo('Threatens to cancel?')]);
 *     $result->yesNo('churn')->yes();
 */
final class Laya
{
    private readonly string $baseUrl;

    private readonly ClientInterface $http;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param  ClientInterface|null  $httpClient  any PSR-18 client; discovered when omitted
     */
    public function __construct(
        string $baseUrl = 'http://localhost:8000',
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
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
     * yes/no answers a bool or a probability. An unregistered question throws.
     *
     * @param  array<string, string|int|float|bool|\BackedEnum>  $answers
     */
    public static function fake(array $answers = []): self
    {
        return new self('http://laya.test', httpClient: new FakeHttpClient($answers));
    }

    /**
     * Ask laya one or more typed questions about $state.
     *
     * @param  string|array<mixed>|\JsonSerializable  $state  text, a JSON document, or a list of conversation turns
     * @param  array<string, Question>  $questions  question id => question
     * @param  Model|null  $model  pin a checkpoint; null lets laya's router pick by language
     */
    public function predict(string|array|\JsonSerializable $state, array $questions, ?Model $model = null): Result
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

        $body = ['state' => $state, 'questions' => (object) $wire];
        if ($model !== null) {
            $body['model'] = $model->value;
        }

        $request = $this->request('POST', '/v1/systemone')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));

        return Result::fromArray($this->send($request));
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
    public function decide(string|array|\JsonSerializable $state, string $class, ?Model $model = null): object
    {
        return DecisionMapper::hydrate($class, $this->predict($state, DecisionMapper::questions($class), $model));
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

    public function assertPredictedCount(int $count): void
    {
        $actual = count($this->fakeClient()->requests);
        self::assert($actual === $count, sprintf('Expected %d laya prediction(s), but %d were made.', $count, $actual));
    }

    public function assertNothingPredicted(): void
    {
        $this->assertPredictedCount(0);
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

        throw match (true) {
            $status === 401 => new AuthenticationException($message, $status),
            $status === 503 => new ServerBusyException($message, $status),
            in_array($status, [400, 413, 422], true) => new ValidationException($message, $status),
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
        } elseif (! $condition) {
            throw new \AssertionError($message);
        }
    }
}
