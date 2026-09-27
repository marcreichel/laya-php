<?php

declare(strict_types=1);

use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Exceptions\AuthenticationException;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Exceptions\TransportException;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

function questions(): array
{
    return [
        'department' => Question::choice('Which department?', ['billing' => 'refunds', 'technical' => 'bugs', 'other' => 'rest']),
        'urgency' => Question::score('How urgent?', ['not urgent', 'soon', 'blocking']),
        'churn' => Question::yesNo('Threatens to cancel?'),
    ];
}

it('posts state, questions and model to /v1/systemone with the bearer token', function () {
    $sent = [];
    layaRespondingWith(200, LAYA_RESPONSE, $sent, apiKey: 's3cret')
        ->predict(['subject' => 'Refund', 'body' => 'Billed twice'], questions(), model: Model::Multilingual);

    $body = json_decode((string) $sent[0]->getBody(), true);
    expect((string) $sent[0]->getUri())->toBe('http://laya.local/v1/systemone')
        ->and($sent[0]->getHeaderLine('Authorization'))->toBe('Bearer s3cret')
        ->and($body['state'])->toBe(['subject' => 'Refund', 'body' => 'Billed twice'])
        ->and($body['model'])->toBe('multilingual')
        ->and(array_keys($body['questions']))->toBe(['department', 'urgency', 'churn']);
});

it('leaves the model out so laya routes by language', function () {
    $sent = [];
    layaRespondingWith(200, LAYA_RESPONSE, $sent)->predict('Billed twice', questions());

    expect(json_decode((string) $sent[0]->getBody(), true))->not->toHaveKey('model')
        ->and($sent[0]->hasHeader('Authorization'))->toBeFalse();
});

it('maps every answer type onto typed answers', function () {
    $result = layaRespondingWith(200, LAYA_RESPONSE)->predict('Billed twice', questions());

    expect($result['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($result->choice('department')->choice)->toBe('billing')
        ->and($result->choice('department')->is('billing'))->toBeTrue()
        ->and($result->choice('department')->probabilities['billing'])->toBe(0.91)
        ->and($result->choice('department')->answerConfidence)->toBe(0.9)
        ->and($result->score('urgency')->score)->toBe(1.74)
        ->and($result->score('urgency')->level())->toBe(2)
        ->and($result->score('urgency')->label())->toBe('blocking')
        ->and($result->yesNo('churn')->probability)->toBe(0.83)
        ->and($result->yesNo('churn')->yes())->toBeTrue()
        ->and($result->yesNo('churn')->yes(threshold: 0.9))->toBeFalse()
        ->and($result->routedModel)->toBe('english')
        ->and($result->inputTokens)->toBe(42)
        ->and($result)->toHaveCount(3);
});

it('names the question when it is asked for the wrong answer type or a missing id', function () {
    $result = layaRespondingWith(200, LAYA_RESPONSE)->predict('Billed twice', questions());

    expect(fn () => $result->score('churn'))->toThrow(UnexpectedValueException::class, '"churn"')
        ->and(fn () => $result['nope'])->toThrow(OutOfBoundsException::class, 'Asked: department, urgency, churn');
});

it('rejects question lists without string ids or Question values', function (array $questions) {
    expect(fn () => layaRespondingWith(200, LAYA_RESPONSE)->predict('x', $questions))->toThrow(InvalidQuestionException::class);
})->with([
    'list' => [[Question::yesNo('Cancel?')]],
    'raw array' => [['churn' => ['type' => 'noul', 'instructions' => 'Cancel?']]],
]);

it('maps error statuses onto exceptions carrying laya\'s detail', function (int $status, string $class) {
    expect(fn () => layaRespondingWith($status, ['detail' => "question 'x': no 'instructions'"])->predict('x', questions()))
        ->toThrow($class, "question 'x': no 'instructions'");
})->with([
    [400, ValidationException::class],
    [413, ValidationException::class],
    [422, ValidationException::class],
    [401, AuthenticationException::class],
    [503, ServerBusyException::class],
    [500, ServerException::class],
]);

it('treats a non-JSON success body as a server error', function () {
    expect(fn () => layaRespondingWith(200, '<html>proxy</html>')->predict('x', questions()))->toThrow(ServerException::class);
});

it('wraps network failures in a TransportException', function () {
    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};
        }
    };

    expect(fn () => new Laya(httpClient: $client)->health())->toThrow(TransportException::class, 'Connection refused');
});

it('reads the health probe', function () {
    $health = layaRespondingWith(200, ['status' => 'ok', 'loaded' => ['english'], 'revisions' => ['english' => 'abc'], 'device' => 'cpu'])->health();

    expect($health->ok)->toBeTrue()->and($health->loaded)->toBe(['english'])->and($health->device)->toBe('cpu');
});

it('is a read-only, countable, iterable map of answers', function () {
    $result = layaRespondingWith(200, LAYA_RESPONSE)->predict('Billed twice', questions());

    expect(isset($result['churn']))->toBeTrue()
        ->and(isset($result['nope']))->toBeFalse()
        ->and(array_keys(iterator_to_array($result)))->toBe(['department', 'urgency', 'churn'])
        ->and(function () use ($result) {
            $result['churn'] = null;
        })->toThrow(LogicException::class, 'immutable')
        ->and(function () use ($result) {
            unset($result['churn']);
        })->toThrow(LogicException::class, 'immutable');
});

it('rounds the expected score when laya sends no level probabilities', function () {
    $response = LAYA_RESPONSE;
    $response['answers']['urgency']['probabilities'] = [];

    expect(layaRespondingWith(200, $response)->predict('x', questions())->score('urgency')->level())->toBe(2);
});

it('treats a response that is not laya-shaped as a server error', function (array $response, string $message) {
    expect(fn () => layaRespondingWith(200, $response)->predict('x', questions()))->toThrow(ServerException::class, $message);
})->with([
    'no answers' => [['detail' => 'ok'], 'has no answers'],
    'choice without a choice' => [['answers' => ['department' => ['type' => 'choice']]], 'has no choice'],
]);
