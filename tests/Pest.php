<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;
use MarcReichel\Laya\Testing\FakeHttpClient;
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

/** Six labelled tickets and the fake's answers to them: some right, some wrong, at varied confidence. */
function triageClient(): FakeHttpClient
{
    $client = new FakeHttpClient([]);
    $client->sequence(
        ['department' => 'billing', 'urgency' => 2, 'churn' => 0.9],
        ['department' => 'billing', 'urgency' => 1.3, 'churn' => 0.3],
        ['department' => 'billing', 'urgency' => 0, 'churn' => 0.6],
        ['department' => 'technical', 'urgency' => 2, 'churn' => 0.7],
        ['department' => null, 'urgency' => 1, 'churn' => 0.2],
        ['department' => 'billing', 'urgency' => 2, 'churn' => 0.4],
    );

    return $client;
}

function triageRows(): array
{
    return [
        ['state' => 's1', 'department' => 'billing', 'urgency' => '2', 'churn' => 'true'],
        ['state' => 's2', 'department' => 'billing', 'urgency' => '1', 'churn' => 'false'],
        ['state' => 's3', 'department' => 'technical', 'urgency' => '0', 'churn' => 'true'],
        ['state' => 's4', 'department' => 'technical', 'urgency' => '', 'churn' => 'false'],
        ['state' => 's5', 'department' => 'other', 'urgency' => '1', 'churn' => 'false'],
        ['state' => 's6', 'department' => 'billing', 'urgency' => '2', 'churn' => 'true'],
    ];
}

const TRIAGE_REPORT = <<<'TXT'
    App\Decisions\Triage: 6 rows, target precision 0.80

    department: 6 labelled
      accuracy 0.67
      confusion (label down, answer across):
                     billing  technical      other
          billing          3          0          0
        technical          1          1          0
            other          1          0          0
      calibration (answer confidence: accuracy):
        0.0-0.1  0.00 of 1
        0.9-1.0  0.80 of 5
      minConfidence 1 reaches precision 0.80 and sends 17% to a human

    urgency: 5 labelled
      accuracy 1.00
      confusion (label down, answer across):
           0  1  2
        0  1  0  0
        1  0  2  0
        2  0  0  2
      calibration (answer confidence: accuracy):
        0.9-1.0  1.00 of 5
      minConfidence 1 reaches precision 1.00 and sends 0% to a human

    churn: 6 labelled
      accuracy 0.67, precision 0.67, recall 0.67
      calibration (answer confidence: accuracy):
        0.6-0.7  0.50 of 2
        0.7-0.8  0.50 of 2
        0.8-0.9  1.00 of 1
        0.9-1.0  1.00 of 1
      minConfidence 0.8 reaches precision 1.00 and sends 67% to a human
      threshold 0.4 has the best F1 (0.86)
    TXT;

/** triageRows() as a CSV file. */
function triageCsv(): string
{
    return labelledFile('csv', implode("\n", array_map(fn (array $row) => implode(',', $row), [array_keys(triageRows()[0]), ...triageRows()]))."\n");
}

/** A file named *.$extension holding $contents, removed after the test run. */
function labelledFile(string $extension, string $contents): string
{
    $path = sys_get_temp_dir().'/laya-'.bin2hex(random_bytes(4)).'.'.$extension;
    file_put_contents($path, $contents);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}
