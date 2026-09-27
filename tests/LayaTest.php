<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;
use MarcReichel\Laya\Exceptions\AuthenticationException;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Exceptions\TransportException;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use MarcReichel\Laya\Result;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

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

it('builds requests with the factories it is given', function () {
    $factory = new class implements RequestFactoryInterface, StreamFactoryInterface
    {
        /** @var list<string> */
        public array $calls = [];

        public function createRequest(string $method, $uri): RequestInterface
        {
            $this->calls[] = 'request';

            return new Request($method, $uri);
        }

        public function createStream(string $content = ''): StreamInterface
        {
            $this->calls[] = 'stream';

            return Utils::streamFor($content);
        }

        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
        {
            throw new LogicException('unused');
        }

        public function createStreamFromResource($resource): StreamInterface
        {
            throw new LogicException('unused');
        }
    };
    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return new Response(200, [], json_encode(LAYA_RESPONSE));
        }
    };

    new Laya(httpClient: $client, requestFactory: $factory, streamFactory: $factory)->predict('x', questions());

    expect($factory->calls)->toBe(['request', 'stream']);
});

it('encodes the body as UTF-8 JSON, with questions as an object even when empty', function () {
    $sent = [];
    layaRespondingWith(200, ['answers' => []], $sent)->predict('Müller zahlt doppelt', []);

    expect((string) $sent[0]->getBody())->toBe('{"state":"Müller zahlt doppelt","questions":{}}')
        ->and(fn () => layaRespondingWith(200, LAYA_RESPONSE)->predict("\xB1", questions()))->toThrow(JsonException::class);
});

it('only treats 2xx statuses as success', function (int $status, bool $ok) {
    $predict = fn () => layaRespondingWith($status, LAYA_RESPONSE)->predict('x', questions());

    $ok ? expect($predict())->toHaveCount(3) : expect($predict)->toThrow(ServerException::class, "(HTTP {$status})");
})->with([
    [199, false],
    [299, true],
    [300, false],
]);

it('builds the error message from laya\'s detail, falling back to the reason phrase', function (array $body, string $message) {
    expect(fn () => layaRespondingWith(422, $body)->predict('x', questions()))
        ->toThrow(fn (ValidationException $e) => expect($e->getMessage())->toBe($message));
})->with([
    'string detail' => [['detail' => 'no instructions'], 'laya-serve: no instructions (HTTP 422)'],
    'list detail' => [['detail' => [['msg' => 'bad']]], 'laya-serve: [{"msg":"bad"}] (HTTP 422)'],
    'no detail' => [['oops' => true], 'laya-serve: Unprocessable Entity (HTTP 422)'],
]);

it('gives a transport failure code 0 and a non-laya-shaped response status 200', function () {
    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};
        }
    };

    expect(fn () => new Laya(httpClient: $client)->health())->toThrow(fn (TransportException $e) => expect($e->getCode())->toBe(0))
        ->and(fn () => layaRespondingWith(200, ['detail' => 'ok'])->predict('x', questions()))->toThrow(fn (ServerException $e) => expect($e->status)->toBe(200))
        ->and(fn () => layaRespondingWith(200, ['answers' => ['x' => 'garbage']])->predict('x', questions()))->toThrow(fn (ServerException $e) => expect($e->status)->toBe(200))
        ->and(fn () => layaRespondingWith(200, ['answers' => ['x' => ['type' => 'choice']]])->predict('x', questions()))->toThrow(fn (ServerException $e) => expect($e->status)->toBe(200));
});

it('tolerates malformed routing and usage', function () {
    $result = Result::fromArray(['answers' => [], 'routing' => 'garbage', 'usage' => 'garbage']);
    $noInt = Result::fromArray(['answers' => [], 'usage' => ['input_tokens' => 'many']]);

    expect($result->routedModel)->toBeNull()
        ->and($result->routing)->toBe(['garbage'])
        ->and($result->inputTokens)->toBe(0)
        ->and(Result::fromArray(['answers' => []])->inputTokens)->toBe(0)
        ->and($noInt->inputTokens)->toBe(0);
});

it('reads numeric answer ids and offsets as strings', function () {
    $result = Result::fromArray(['answers' => [0 => ['type' => 'noul', 'noul' => 0.9]]]);

    expect($result->has('0'))->toBeTrue()
        ->and(isset($result[0]))->toBeTrue()
        ->and($result[0])->toBeInstanceOf(YesNoAnswer::class);
});

it('reads health, dropping what is not laya-shaped', function () {
    $health = layaRespondingWith(200, ['status' => 'ok', 'loaded' => ['english', 5, 'multilingual'], 'revisions' => ['english' => 'abc', 'multilingual' => null], 'device' => 'cpu'])->health();
    $garbage = layaRespondingWith(200, ['status' => 'degraded', 'loaded' => 'english', 'revisions' => 'abc', 'device' => 5])->health();

    expect($health->loaded)->toBe(['english', 'multilingual'])
        ->and($health->revisions)->toBe(['english' => 'abc', 'multilingual' => null])
        ->and($garbage->ok)->toBeFalse()
        ->and($garbage->loaded)->toBe(['english'])
        ->and($garbage->revisions)->toBe([0 => 'abc'])
        ->and($garbage->device)->toBe('auto');
});
