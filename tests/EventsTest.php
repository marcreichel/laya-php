<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Events\PredictionFailed;
use MarcReichel\Laya\Events\PredictionMade;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class EventTriage
{
    public function __construct(
        #[Ask('Threatens to cancel?')] public bool $churn,
    ) {}
}

it('dispatches a PredictionMade for each prediction, without the state', function () {
    $events = new RecordingDispatcher;
    $result = layaRespondingWith(200, LAYA_RESPONSE, events: $events)->predict('Billed twice', questions());

    expect($events->events)->toHaveCount(1);
    $event = $events->events[0];
    expect($event)->toBeInstanceOf(PredictionMade::class)
        ->and($event->questionIds)->toBe(['department', 'urgency', 'churn'])
        ->and($event->model)->toBeNull()
        ->and($event->routedModel)->toBe('english')
        ->and($event->truncated)->toBeFalse()
        ->and($event->cached)->toBeFalse()
        ->and($event->durationMs)->toBeGreaterThan(0.0)->toBeLessThan(10_000.0)
        ->and($event->inputTokens)->toBe(42)
        ->and($event->result)->toBe($result)
        ->and($event->state)->toBeNull();
});

it('leaves the state off by default', function () {
    $events = new RecordingDispatcher;
    $laya = new Laya(httpClient: new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return new Response(200, [], json_encode(LAYA_RESPONSE));
        }
    }, events: $events);

    $laya->predict('Billed twice', questions());

    expect($events->events[0]->state)->toBeNull();
});

it('puts the pinned model, truncation and, when asked, the state on the event', function () {
    $events = new RecordingDispatcher;
    $response = ['usage' => ['input_tokens' => 512, 'truncated' => true]] + LAYA_RESPONSE;
    layaRespondingWith(200, $response, events: $events, includeState: true)->predict(['body' => 'Billed twice'], questions(), Model::English);

    expect($events->events[0]->model)->toBe(Model::English)
        ->and($events->events[0]->truncated)->toBeTrue()
        ->and($events->events[0]->inputTokens)->toBe(512)
        ->and($events->events[0]->state)->toBe(['body' => 'Billed twice']);
});

it('marks cache hits, which take no time', function () {
    $events = new RecordingDispatcher;
    $laya = layaBatching(cache: new Repository(new ArrayStore), events: $events, includeState: true);

    $laya->predict('Billed twice', questions());
    $result = $laya->predict('Billed twice', questions(), maxLen: 64);
    $hit = $laya->predict('Billed twice', questions(), maxLen: 64);

    [, $miss, $cached] = $events->events;
    expect($events->events)->toHaveCount(3)
        ->and($miss->cached)->toBeFalse()
        ->and($cached)->toBeInstanceOf(PredictionMade::class)
        ->and($cached->cached)->toBeTrue()
        ->and($cached->durationMs)->toBe(0.0)
        ->and($cached->routedModel)->toBe('Billed twice')
        ->and($cached->questionIds)->toBe(['department', 'urgency', 'churn'])
        ->and($cached->result)->toBe($hit)->toEqual($result)
        ->and($cached->state)->toBe('Billed twice');
});

it('dispatches a PredictionFailed with the exception before throwing it', function (bool $includeState, ?string $state) {
    $events = new RecordingDispatcher;
    $laya = layaRespondingWith(503, ['detail' => 'busy'], events: $events, includeState: $includeState);

    try {
        $laya->predict('Billed twice', questions(), Model::Multilingual);
        $this->fail('Expected an exception.');
    } catch (ServerBusyException $e) {
        expect($events->events)->toHaveCount(1);
        $event = $events->events[0];
        expect($event)->toBeInstanceOf(PredictionFailed::class)
            ->and($event->exception)->toBe($e)
            ->and($event->questionIds)->toBe(['department', 'urgency', 'churn'])
            ->and($event->model)->toBe(Model::Multilingual)
            ->and($event->durationMs)->toBeGreaterThan(0.0)->toBeLessThan(10_000.0)
            ->and($event->state)->toBe($state);
    }
})->with([
    'without the state' => [false, null],
    'with the state' => [true, 'Billed twice'],
]);

it('counts a response that is not laya-shaped as a failure', function () {
    $events = new RecordingDispatcher;

    expect(fn () => layaRespondingWith(200, ['detail' => 'ok'], events: $events)->predict('x', questions()))->toThrow(ServerException::class)
        ->and($events->events)->toHaveCount(1)
        ->and($events->events[0])->toBeInstanceOf(PredictionFailed::class);
});

it('dispatches a PredictionMade for each state of a batch, hits first, misses with their request\'s duration', function () {
    $events = new RecordingDispatcher;
    $laya = layaBatching(cache: new Repository(new ArrayStore), events: $events, includeState: true);
    $laya->predict('cached', questions(), Model::English);
    $events->events = [];

    $results = $laya->predictMany(['a' => 'new', 'b' => 'cached', 'c' => 'newer'], questions(), Model::English);

    [$b, $a, $c] = $events->events;
    expect($events->events)->toHaveCount(3)
        ->and($b->cached)->toBeTrue()
        ->and($b->durationMs)->toBe(0.0)
        ->and($b->state)->toBe('cached')
        ->and($b->result)->toBe($results['b'])
        ->and($b->model)->toBe(Model::English)
        ->and($a->cached)->toBeFalse()
        ->and($a->state)->toBe('new')
        ->and($a->routedModel)->toBe('new')
        ->and($a->result)->toBe($results['a'])
        ->and($a->questionIds)->toBe(['department', 'urgency', 'churn'])
        ->and($a->model)->toBe(Model::English)
        ->and($a->durationMs)->toBeGreaterThan(0.0)->toBeLessThan(10_000.0)
        ->and($c->state)->toBe('newer')
        ->and($c->result)->toBe($results['c'])
        ->and($c->durationMs)->toBe($a->durationMs);
});

it('dispatches a PredictionMade for every copy of an identical state, in input order', function () {
    $events = new RecordingDispatcher;
    $results = layaBatching(events: $events, includeState: true)->predictMany(['a' => 'x', 'b' => 'y', 'c' => 'x'], questions());

    [$a, $b, $c] = $events->events;
    expect($events->events)->toHaveCount(3)
        ->and([$a->state, $b->state, $c->state])->toBe(['x', 'y', 'x'])
        ->and($c->cached)->toBeFalse()
        ->and($c->result)->toBe($results['c'])->toBe($a->result)
        ->and($c->durationMs)->toBe($a->durationMs)->toBe($b->durationMs);
});

it('lists every copy on the PredictionFailed of a batch', function () {
    $events = new RecordingDispatcher;

    expect(fn () => layaRespondingWith(404, ['detail' => 'Not Found'], events: $events, includeState: true)->predictMany(['a' => 'x', 'b' => 'y', 'c' => 'x'], questions()))
        ->toThrow(ServerException::class)
        ->and($events->events[0]->state)->toBe(['a' => 'x', 'b' => 'y', 'c' => 'x']);
});

it('leaves the states of a batch off its events unless asked', function () {
    $events = new RecordingDispatcher;
    layaBatching(events: $events)->predictMany(['a' => 'new'], questions());

    expect($events->events[0]->state)->toBeNull();
});

it('dispatches one PredictionFailed for a failed batch request, with the exception it throws', function (bool $includeState, ?array $state) {
    $events = new RecordingDispatcher;
    $laya = layaRespondingWith(404, ['detail' => 'Not Found'], events: $events, includeState: $includeState);

    try {
        $laya->predictMany(['a' => 'x', 'b' => ['body' => 'y']], questions(), Model::English);
        $this->fail('Expected an exception.');
    } catch (ServerException $e) {
        expect($events->events)->toHaveCount(1);
        $event = $events->events[0];
        expect($event)->toBeInstanceOf(PredictionFailed::class)
            ->and($event->exception)->toBe($e)
            ->and($e->getMessage())->toContain('0.3.22')
            ->and($event->questionIds)->toBe(['department', 'urgency', 'churn'])
            ->and($event->model)->toBe(Model::English)
            ->and($event->durationMs)->toBeGreaterThan(0.0)->toBeLessThan(10_000.0)
            ->and($event->state)->toBe($state);
    }
})->with([
    'without the states' => [false, null],
    'with the states' => [true, ['a' => 'x', 'b' => ['body' => 'y']]],
]);

it('counts a batch response without one result per state, or with an answer that is not laya-shaped, as a failure', function (array $body) {
    $events = new RecordingDispatcher;

    expect(fn () => layaRespondingWith(200, $body, events: $events)->predictMany(['x'], questions()))->toThrow(ServerException::class)
        ->and($events->events)->toHaveCount(1)
        ->and($events->events[0])->toBeInstanceOf(PredictionFailed::class);
})->with([
    'no results' => [['answers' => []]],
    'not laya-shaped' => [['results' => [['detail' => 'ok']]]],
]);

it('only sends the states of the failed request on its event', function () {
    $events = new RecordingDispatcher;
    $sent = 0;
    $laya = new Laya(httpClient: new class($sent) implements ClientInterface
    {
        public function __construct(private int &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $states = json_decode((string) $request->getBody(), true)['states'];

            return ++$this->sent === 1
                ? new Response(200, [], json_encode(['results' => array_fill(0, count($states), LAYA_RESPONSE)]))
                : new Response(500, [], json_encode(['detail' => 'boom']));
        }
    }, events: $events, includeState: true);

    // 66 distinct states, each twice: the copies of the second request's states are on its event too.
    expect(fn () => $laya->predictMany(array_map(fn ($i) => 'state '.($i % 66), range(0, 131)), questions()))->toThrow(ServerException::class, 'boom')
        ->and($events->events)->toHaveCount(129)
        ->and($events->events[127])->toBeInstanceOf(PredictionMade::class)
        ->and($events->events[128])->toBeInstanceOf(PredictionFailed::class)
        ->and($events->events[128]->state)->toBe([64 => 'state 64', 65 => 'state 65', 130 => 'state 64', 131 => 'state 65']);
});

it('dispatches from decide() and decideMany()', function () {
    $events = new RecordingDispatcher;
    $laya = layaBatching(events: $events);

    $laya->decide('Cancel now', EventTriage::class);
    $laya->decideMany(['Cancel now', 'Thanks'], EventTriage::class);

    expect($events->events)->toHaveCount(3)
        ->and(array_map(fn (PredictionMade $e) => $e->questionIds, $events->events))->each->toBe(['churn']);
});

it('dispatches nothing for invalid questions, which are never sent', function () {
    $events = new RecordingDispatcher;

    expect(fn () => layaBatching(events: $events)->predict('x', ['churn' => 'Cancel?']))->toThrow(InvalidQuestionException::class)
        ->and($events->events)->toBe([]);
});

it('throws the laya error, not a listener\'s, when a PredictionFailed listener fails', function (Closure $predict) {
    $events = new class implements EventDispatcherInterface
    {
        public function dispatch(object $event): object
        {
            throw new RuntimeException('listener failed');
        }
    };

    expect(fn () => $predict(layaRespondingWith(503, ['detail' => 'busy'], events: $events)))->toThrow(ServerBusyException::class, 'busy');
})->with([
    'predict' => [fn (Laya $laya) => $laya->predict('x', questions())],
    'predictMany' => [fn (Laya $laya) => $laya->predictMany(['x'], questions())],
]);

it('dispatches from a fake, for every state of a batch, without the state unless asked', function (?bool $includeState, ?string $state) {
    $events = new RecordingDispatcher;
    $laya = Laya::fake(['churn' => true], events: $events, includeState: $includeState);

    $result = $laya->predict('Cancel now', ['churn' => Question::yesNo('Cancel?')], Model::English);
    $laya->decideMany(['a' => 'Cancel now', 'b' => 'Thanks'], EventTriage::class);

    expect($events->events)->toHaveCount(3)
        ->and($events->events[0])->toBeInstanceOf(PredictionMade::class)
        ->and($events->events[0]->result)->toBe($result)
        ->and($events->events[0]->model)->toBe(Model::English)
        ->and($events->events[0]->cached)->toBeFalse()
        ->and($events->events[0]->state)->toBe($state)
        ->and($events->events[2]->questionIds)->toBe(['churn']);
})->with([
    'by default' => [null, null],
    'when asked' => [true, 'Cancel now'],
]);

it('dispatches nothing from a fake without a dispatcher', function () {
    expect(Laya::fake(['churn' => true])->predict('x', ['churn' => Question::yesNo('Cancel?')])->yesNo('churn')->yes())->toBeTrue();
});

it('predicts without a dispatcher', function () {
    expect(layaRespondingWith(200, LAYA_RESPONSE)->predict('x', ['churn' => Question::yesNo('Cancel?')])->yesNo('churn')->yes())->toBeTrue();
});
