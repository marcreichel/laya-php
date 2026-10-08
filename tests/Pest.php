<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A Laya that answers every request with $status/$body and records what it sent.
 *
 * @param  array<string, mixed>|string  $body
 * @param  list<RequestInterface>  $sent
 */
function layaRespondingWith(int $status, array|string $body, array &$sent = [], ?string $apiKey = null, ?EventDispatcherInterface $events = null, bool $includeState = false): Laya
{
    $client = new class($status, is_string($body) ? $body : json_encode($body), $sent) implements ClientInterface
    {
        /** @param list<RequestInterface> $sent */
        public function __construct(private int $status, private string $body, private array &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;

            return new Response($this->status, ['Content-Type' => 'application/json'], $this->body);
        }
    };

    return new Laya('http://laya.local/', apiKey: $apiKey, httpClient: $client, events: $events, includeState: $includeState);
}

/** A PSR-14 dispatcher that keeps every event it's given. */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

/**
 * A Laya whose batch endpoint answers each state with LAYA_RESPONSE, routed to the state itself.
 *
 * @param  list<RequestInterface>  $sent
 */
function layaBatching(array &$sent = [], ?CacheInterface $cache = null, ?EventDispatcherInterface $events = null, bool $includeState = false): Laya
{
    $client = new class($sent) implements ClientInterface
    {
        /** @param list<RequestInterface> $sent */
        public function __construct(private array &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;
            $body = json_decode((string) $request->getBody(), true);
            $results = array_map(fn ($state) => ['routing' => ['model' => $state]] + LAYA_RESPONSE, $body['states'] ?? [$body['state']]);

            return new Response(200, [], json_encode(isset($body['states']) ? ['results' => $results] : $results[0]));
        }
    };

    return new Laya('http://laya.local', httpClient: $client, cache: $cache, cacheTtl: 60, events: $events, includeState: $includeState);
}

// A response in the exact shape laya's Router.predict() returns.
const LAYA_RESPONSE = [
    'model' => 'laya-rl-agent',
    'answers' => [
        'department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 0.91, 'technical' => 0.06, 'other' => 0.03],
            'confidence' => 0.87, 'answer_confidence' => 0.9, 'action' => ['act_probability' => 0.5]],
        'urgency' => ['type' => 'score', 'score' => 1.74, 'legend' => ['0' => 'not urgent', '1' => 'soon', '2' => 'blocking'],
            'probabilities' => ['0' => 0.05, '1' => 0.16, '2' => 0.79], 'confidence' => 0.7, 'answer_confidence' => 0.75, 'action' => ['act_probability' => 0.5]],
        'churn' => ['type' => 'noul', 'noul' => 0.83, 'confidence' => 0.83, 'answer_confidence' => 0.8, 'action' => ['act_probability' => 0.5]],
    ],
    'usage' => ['input_tokens' => 42, 'output_tokens' => 0],
    'routing' => ['model' => 'english', 'reason' => 'latin script, english'],
];

// A response in the shape of TypeSafe's OpenAPI spec (https://api.typesafe.ai/openapi.json), as sys1 sends it:
// no answer_confidence, no confidence on yes/no answers, no routing.
const TYPESAFE_RESPONSE = [
    'model' => 'convaiinnovations/laya',
    'answers' => [
        'department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 0.988, 'technical' => 0.007, 'other' => 0.005], 'confidence' => 0.934],
        'urgency' => ['type' => 'score', 'score' => 1.74, 'probabilities' => ['0' => 0.05, '1' => 0.16, '2' => 0.79], 'legend' => ['0' => 'not urgent', '1' => 'soon', '2' => 'blocking'], 'confidence' => 0.71],
        'churn' => ['type' => 'noul', 'noul' => 0.83],
    ],
    'usage' => ['input_tokens' => 42, 'output_tokens' => 0],
];

function questions(): array
{
    return [
        'department' => Question::choice('Which department?', ['billing' => 'refunds', 'technical' => 'bugs', 'other' => 'rest']),
        'urgency' => Question::score('How urgent?', ['not urgent', 'soon', 'blocking']),
        'churn' => Question::yesNo('Threatens to cancel?'),
    ];
}
