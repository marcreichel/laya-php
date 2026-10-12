<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Illuminate\Container\Container;
use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use MarcReichel\Laya\Result;
use MarcReichel\Laya\Testing\Limits;
use PHPUnit\Framework\AssertionFailedError;

enum Desk: string
{
    case Billing = 'billing';
    case Technical = 'technical';
    case Other = 'other';
}

final readonly class Routing
{
    public function __construct(
        #[Ask('Which desk?')] public Desk $desk,
        #[Ask('Threatens to cancel?')] public bool $churn,
    ) {}
}

it('answers from registered values and records predictions', function () {
    $laya = Laya::fake(['dept' => 'billing', 'urgency' => 1, 'churn' => 0.3]);

    $result = $laya->predict('Billed twice', [
        'dept' => Question::choice('Dept?', ['billing', 'other']),
        'urgency' => Question::score('Urgent?', ['low', 'mid', 'high']),
        'churn' => Question::yesNo('Cancel?'),
    ], model: Model::English);

    expect($result->choice('dept')->choice)->toBe('billing')
        ->and($result->score('urgency')->label())->toBe('mid')
        ->and($result->yesNo('churn')->no())->toBeTrue()
        ->and($result->routedModel)->toBe('english');

    $laya->assertPredictedCount(1);
    $laya->assertPredicted(fn ($state, $questions, $model) => $state === 'Billed twice' && $model === 'english');
});

it('passes a null model to assertions when laya routes', function () {
    $laya = Laya::fake(['churn' => true]);
    $laya->predict('Billed twice', ['churn' => Question::yesNo('Cancel?')]);

    expect(fn () => $laya->assertPredicted(fn ($state, $questions, $model) => $model === null))->not->toThrow(AssertionFailedError::class);
});

it('reports the multilingual checkpoint when no model is pinned', function () {
    $result = Laya::fake(['churn' => true])->predict('Billed twice', ['churn' => Question::yesNo('Cancel?')]);

    expect($result->routedModel)->toBe('multilingual');
});

it('fails loudly on unregistered questions and impossible answers', function () {
    expect(fn () => Laya::fake()->predict('x', ['churn' => Question::yesNo('Cancel?')]))->toThrow(LogicException::class, 'no answer for question "churn"')
        ->and(fn () => Laya::fake(['d' => 'sales'])->predict('x', ['d' => Question::choice('Dept?', ['billing'])]))->toThrow(LogicException::class, 'not one of its options')
        ->and(fn () => Laya::fake(['u' => 5])->predict('x', ['u' => Question::score('Urgent?', ['low'])]))->toThrow(LogicException::class, 'must be a level index from 0 to 0.')
        ->and(fn () => Laya::fake(['c' => 'yes'])->predict('x', ['c' => Question::yesNo('Cancel?')]))->toThrow(LogicException::class, 'bool or a probability');
});

it('fails assertions that do not hold', function () {
    expect(fn () => Laya::fake()->assertPredicted())->toThrow(AssertionFailedError::class)
        ->and(fn () => Laya::fake()->assertNothingPredicted())->not->toThrow(AssertionFailedError::class)
        ->and(fn () => layaRespondingWith(200, [])->assertNothingPredicted())->toThrow(LogicException::class, 'Laya::fake()');
});

it('reports a healthy server', function () {
    expect(Laya::fake()->health()->ok)->toBeTrue();
});

it('answers in the exact shape laya-serve does', function () {
    $result = Laya::fake(['dept' => 'billing', 'urgency' => 1, 'churn' => 0.3])->predict('x', [
        'dept' => Question::choice('Dept?', ['billing', 'other']),
        'urgency' => Question::score('Urgent?', ['low', 'mid', 'high']),
        'churn' => Question::yesNo('Cancel?'),
    ], model: Model::Multilingual);

    expect($result->raw)->toBe([
        'model' => 'laya-fake',
        'answers' => [
            'dept' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 1.0, 'answer_confidence' => 1.0, 'probabilities' => ['billing' => 1.0, 'other' => 0.0]],
            'urgency' => ['type' => 'score', 'score' => 1.0, 'confidence' => 1.0, 'answer_confidence' => 1.0, 'legend' => ['low', 'mid', 'high'], 'probabilities' => [0.0, 1.0, 0.0]],
            'churn' => ['type' => 'noul', 'noul' => 0.3, 'confidence' => 0.7, 'answer_confidence' => 0.7],
        ],
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        'routing' => ['model' => 'multilingual', 'reason' => 'fake'],
    ]);
});

it('answers numeric choice labels as strings', function () {
    $answer = Laya::fake(['code' => 2])->predict('x', ['code' => Question::choice('Code?', [1, 2])])->choice('code');

    expect($answer->choice)->toBe('2')
        ->and($answer->probabilities)->toBe([1 => 0.0, 2 => 1.0]);
});

it('reports a healthy fake device', function () {
    expect(Laya::fake()->health()->device)->toBe('fake');
});

it('checks recorded predictions', function () {
    $laya = Laya::fake(['churn' => true]);
    $laya->predict('x', ['churn' => Question::yesNo('Cancel?')]);

    expect(fn () => $laya->assertPredicted())->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertPredicted(fn () => false))->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertPredictedCount(2))->toThrow(AssertionFailedError::class, 'Expected 2 laya prediction(s), but 1 were made.');
});

it('leaves a container without a Laya binding alone', function () {
    Container::setInstance(new Container);

    Laya::fake();

    expect(Container::getInstance()->bound(Laya::class))->toBeFalse();
    Container::setInstance();
});

it('answers null as unsure: even probabilities, zero confidence', function () {
    $result = Laya::fake(['dept' => null, 'urgency' => null, 'churn' => null])->predict('x', [
        'dept' => Question::choice('Dept?', ['billing', 'other']),
        'urgency' => Question::score('Urgent?', ['low', 'mid', 'high']),
        'churn' => Question::yesNo('Cancel?'),
    ]);

    expect($result->raw['answers'])->toBe([
        'dept' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.0, 'answer_confidence' => 0.0, 'probabilities' => ['billing' => 0.5, 'other' => 0.5]],
        'urgency' => ['type' => 'score', 'score' => 1.0, 'confidence' => 0.0, 'answer_confidence' => 0.0, 'legend' => ['low', 'mid', 'high'], 'probabilities' => [1 / 3, 1 / 3, 1 / 3]],
        'churn' => ['type' => 'noul', 'noul' => 0.5, 'confidence' => 0.0, 'answer_confidence' => 0.0],
    ]);
});

it('answers an unsure numeric choice with a string label', function () {
    expect(Laya::fake(['code' => null])->predict('x', ['code' => Question::choice('Code?', [1, 2])])->choice('code')->choice)->toBe('1');
});

it('answers matching states from the first matching when() rule, over the defaults', function () {
    $laya = Laya::fake(['desk' => 'other', 'churn' => false])
        ->when(fn ($state) => str_contains($state, 'refund'), ['desk' => 'billing', 'churn' => true])
        ->when(fn ($state) => str_contains($state, 'refund') || str_contains($state, 'outage'), new Routing(Desk::Technical, churn: false))
        ->when(fn ($state) => str_contains($state, 'invoice'), ['desk' => 'billing']);

    $routings = $laya->decideMany(['A refund, or I cancel', 'An outage', 'Hello', 'An invoice'], Routing::class);

    expect(array_map(fn (Routing $r) => [$r->desk, $r->churn], $routings))->toBe([
        [Desk::Billing, true],
        [Desk::Technical, false],
        [Desk::Other, false],
        [Desk::Billing, false],
    ]);
});

it('answers in sequence, over the defaults, and throws when the sequence runs out', function () {
    $laya = Laya::fake(['churn' => false])
        ->when(fn ($state) => $state === 'vip', ['desk' => 'other'])
        ->sequence(['desk' => 'billing'], new Routing(Desk::Technical, churn: true))
        ->sequence(['desk' => 'other']);

    $routings = $laya->decideMany(['a', 'vip', 'b'], Routing::class);

    expect(array_map(fn (Routing $r) => [$r->desk, $r->churn], $routings))->toBe([
        [Desk::Billing, false],
        [Desk::Other, false],
        [Desk::Technical, true],
    ])
        ->and($laya->decide('c', Routing::class)->desk)->toBe(Desk::Other)
        ->and(fn () => $laya->decide('d', Routing::class))->toThrow(LogicException::class, 'Laya::fake()->sequence() ran out');
});

it('only takes rules and sequences on a fake', function () {
    expect(fn () => layaRespondingWith(200, [])->when(fn () => true, []))->toThrow(LogicException::class, 'Laya::fake()')
        ->and(fn () => layaRespondingWith(200, [])->sequence([]))->toThrow(LogicException::class, 'Laya::fake()');
});

it('asserts predictions that were not made', function () {
    $laya = Laya::fake(['churn' => true]);

    expect(fn () => $laya->assertNotPredicted())->not->toThrow(AssertionFailedError::class);

    $laya->predict('public', ['churn' => Question::yesNo('Cancel?')]);

    expect(fn () => $laya->assertNotPredicted(fn ($state, $questions, $model) => $state === 'secret' && $model === null))->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertNotPredicted(fn ($state, $questions, $model) => $state === 'public' && isset($questions['churn']) && $model === null))->toThrow(AssertionFailedError::class, 'Expected no matching laya prediction, but 1 were made.')
        ->and(fn () => $laya->assertNotPredicted())->toThrow(AssertionFailedError::class);
});

it('asserts decisions that were not made, and how many were', function () {
    $laya = Laya::fake(['desk' => 'billing', 'churn' => true]);
    $laya->predict('x', ['churn' => Question::yesNo('Threatens to cancel?')]);

    expect(fn () => $laya->assertNotDecided(Routing::class))->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertDecidedCount(Routing::class, 0))->not->toThrow(AssertionFailedError::class);

    $laya->decideMany(['a', 'b'], Routing::class);

    expect(fn () => $laya->assertDecidedCount(Routing::class, 2))->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertDecidedCount(Routing::class, 1))->toThrow(AssertionFailedError::class, 'Expected Routing to be decided 1 time(s), but it was decided 2 time(s).')
        ->and(fn () => $laya->assertNotDecided(Routing::class, fn ($state, $model) => $state === 'c' && $model === null))->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertNotDecided(Routing::class, fn ($state, $model) => $state === 'a' && $model === null))->toThrow(AssertionFailedError::class, 'Expected Routing not to be decided, but it was decided 1 time(s).')
        ->and(fn () => $laya->assertNotDecided(Routing::class))->toThrow(AssertionFailedError::class, 'decided 2 time(s)');
});

it('refuses what laya-serve refuses, in its words', function (array $questions, mixed $state, string $message) {
    expect(fn () => Laya::fake()->predict($state, $questions))
        ->toThrow(ValidationException::class, "laya-serve: $message (HTTP 413)");
})->with([
    'questions' => [array_map(fn () => Question::yesNo('?'), array_flip(array_map(fn ($i) => "q$i", range(1, 65)))), 'x', 'too many questions (65 > 64)'],
    'choice options' => [['q' => Question::choice('?', array_map(strval(...), range(1, 101)))], 'x', "too many choice options for 'q' (101 > 100)"],
    'score levels' => [['q' => Question::score('?', array_map(strval(...), range(1, 33)))], 'x', "too many score levels for 'q' (33 > 32)"],
    'total options' => [array_map(fn () => Question::choice('?', array_map(strval(...), range(1, 60))), array_flip(array_map(fn ($i) => "q$i", range(1, 9)))), 'x', 'too many answer options across questions (540 > 512)'],
    'string state' => [['q' => Question::yesNo('?')], str_repeat('é', 50_001), 'state too large (50001 > 50000 chars)'],
    'array state' => [['q' => Question::yesNo('?')], ['body' => str_repeat('x', 50_000)], 'state too large (50011 > 50000 chars)'],
]);

it('refuses a batch over the limit, and accepts one at it', function () {
    $q = ['q' => Question::yesNo('?')];
    $laya = Laya::fake(['q' => true], limits: new Limits(maxBatchSize: 2));
    $client = (fn () => $this->http)->call($laya);
    $post = fn (int $n) => $client->sendRequest(new Request('POST', 'http://laya.test/v1/systemone/batch', [], json_encode(['states' => array_fill(0, $n, 's'), 'questions' => ['q' => ['type' => 'yesno', 'prompt' => '?']]])));

    expect($post(2)->getStatusCode())->toBe(200)
        ->and($post(3)->getStatusCode())->toBe(413)
        ->and((string) $post(3)->getBody())->toContain('too many states in batch (3 > 2)');
});

it('takes custom limits, and none', function () {
    $q = ['q' => Question::yesNo('?')];

    expect(fn () => Laya::fake(['q' => true], limits: new Limits(maxStateLength: 3))->predict('four', $q))->toThrow(ValidationException::class, 'state too large (4 > 3 chars)');
    expect(Laya::fake(['q' => true], limits: new Limits(maxStateLength: null, maxQuestions: null, maxChoiceOptions: null, maxScoreLevels: null, maxTotalOptions: null, maxBatchSize: null))->predict(str_repeat('x', 60_000), $q))->toBeInstanceOf(Result::class);
    expect(Laya::fake(['q' => true], limits: Limits::none())->predict(str_repeat('x', 60_000), $q))->toBeInstanceOf(Result::class);
});

it('names the decision class in the refusal', function () {
    $laya = Laya::fake(['desk' => 'billing', 'churn' => true], limits: new Limits(maxStateLength: 3));

    expect(fn () => $laya->decide('four', Routing::class))->toThrow(ValidationException::class, Routing::class.': laya-serve: state too large (4 > 3 chars) (HTTP 413)')
        ->and(fn () => $laya->decideMany(['four'], Routing::class))->toThrow(ValidationException::class, Routing::class.': laya-serve: state too large');
});

it('measures a structured state by its JSON, unicode and slashes unescaped', function () {
    $q = ['q' => Question::yesNo('?')];

    expect(Laya::fake(['q' => true], limits: new Limits(maxStateLength: 11))->predict(['a' => 'é/é'], $q))->toBeInstanceOf(Result::class)
        ->and(fn () => Laya::fake(['q' => true], limits: new Limits(maxStateLength: 10))->predict(['a' => 'é/é'], $q))->toThrow(ValidationException::class, 'state too large (11 > 10 chars)');
});

it('only counts the options of choice and score questions', function () {
    $laya = Laya::fake(['q' => true]);
    $client = (fn () => $this->http)->call($laya);
    $post = fn (array $questions) => $client->sendRequest(new Request('POST', 'http://laya.test/v1/systemone', [], json_encode(['state' => 's', 'questions' => $questions])));
    $options = array_map(strval(...), range(1, 101));

    expect($post(['q' => ['type' => 'yesno', 'prompt' => '?', 'criteria' => $options]])->getStatusCode())->toBe(200)
        ->and($post(['q' => ['type' => 'yesno', 'prompt' => '?'], 'c' => ['type' => 'choice', 'prompt' => '?', 'criteria' => $options]])->getStatusCode())->toBe(413);
});

it('accepts a batch of 64 by default and refuses 65', function () {
    $laya = Laya::fake(['q' => true]);
    $client = (fn () => $this->http)->call($laya);
    $post = fn (int $n) => $client->sendRequest(new Request('POST', 'http://laya.test/v1/systemone/batch', [], json_encode(['states' => array_fill(0, $n, 's'), 'questions' => ['q' => ['type' => 'yesno', 'prompt' => '?']]])))->getStatusCode();

    expect($post(64))->toBe(200)->and($post(65))->toBe(413);
});
