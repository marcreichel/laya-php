<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BatchTriage
{
    public function __construct(
        #[Ask('Threatens to cancel?')] public bool $churn,
    ) {}
}

it('posts the states with one shared set of questions and keeps their keys', function () {
    $sent = [];
    $results = layaBatching($sent)->predictMany(['t-7' => 'Billed twice', 'x' => ['body' => 'Down again']], questions(), Model::English);

    $body = json_decode((string) $sent[0]->getBody(), true);
    expect((string) $sent[0]->getUri())->toBe('http://laya.local/v1/systemone/batch')
        ->and($sent[0]->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($body)->toHaveKeys(['states', 'questions', 'model'])->not->toHaveKey('state')
        ->and($body['states'])->toBe(['Billed twice', ['body' => 'Down again']])
        ->and($body['model'])->toBe('english')
        ->and(array_keys($body['questions']))->toBe(['department', 'urgency', 'churn'])
        ->and(array_keys($results))->toBe(['t-7', 'x'])
        ->and($results['t-7']->routedModel)->toBe('Billed twice')
        ->and($results['t-7']->choice('department')->choice)->toBe('billing');
});

it('sends the jev-latest alias as model so laya routes each state by language', function () {
    $sent = [];
    layaBatching($sent)->predictMany(['Billed twice'], questions());

    expect(json_decode((string) $sent[0]->getBody(), true))->toHaveKey('model', 'jev-latest');
});

it('sends the token budgets for every state and caches them apart from unbudgeted predictions', function () {
    $sent = [];
    $laya = layaBatching($sent, new Repository(new ArrayStore));
    $laya->predict('long', questions());
    $laya->decideMany(['long'], BatchTriage::class, maxLen: 2048, headMaxLen: 64);

    $body = json_decode((string) $sent[1]->getBody(), true);
    expect($sent)->toHaveCount(2)
        ->and($body['max_len'])->toBe(2048)
        ->and($body['head_max_len'])->toBe(64)
        ->and(json_decode((string) $sent[0]->getBody(), true))->not->toHaveKeys(['max_len', 'head_max_len']);
});

it('sends nothing for no states', function () {
    $sent = [];

    expect(layaBatching($sent)->predictMany([], questions()))->toBe([])
        ->and($sent)->toBe([]);
});

it('splits more than 64 states into requests the server accepts, in order', function () {
    $sent = [];
    $results = layaBatching($sent)->predictMany(array_map(fn ($i) => "state $i", range(0, 129)), questions());

    expect(array_map(fn ($r) => count(json_decode((string) $r->getBody(), true)['states']), $sent))->toBe([64, 64, 2])
        ->and(array_keys($results))->toBe(range(0, 129))
        ->and($results[65]->routedModel)->toBe('state 65')
        ->and($results[129]->routedModel)->toBe('state 129');
});

it('shares the cache with predict() and only sends the states it misses', function () {
    $sent = [];
    $cache = new Repository(new ArrayStore);
    $laya = layaBatching($sent, $cache);

    $laya->predict('cached', questions());
    $results = $laya->predictMany(['a' => 'new', 'b' => 'cached'], questions());
    $again = $laya->predictMany(['b' => 'cached', 'a' => 'new'], questions());

    expect($sent)->toHaveCount(2)
        ->and(json_decode((string) $sent[1]->getBody(), true)['states'])->toBe(['new'])
        ->and(array_keys($results))->toBe(['a', 'b'])
        ->and($results['b']->routedModel)->toBe('cached')
        ->and($again['a'])->toEqual($results['a'])
        ->and($laya->predict('new', questions()))->toEqual($results['a'])
        ->and($sent)->toHaveCount(2);
});

it('says which laya-serve it needs when the batch endpoint is missing', function () {
    expect(fn () => layaRespondingWith(404, ['detail' => 'Not Found'])->predictMany(['x'], questions()))
        ->toThrow(fn (ServerException $e) => expect($e->getMessage())->toContain('0.3.22')
            ->and($e->status)->toBe(404)
            ->and($e->getPrevious())->toBeInstanceOf(ServerException::class));
});

it('passes other errors through unchanged', function () {
    expect(fn () => layaRespondingWith(413, ['detail' => 'too many states in batch (65 > 64)'])->predictMany(['x'], questions()))
        ->toThrow(ValidationException::class, 'too many states')
        ->and(fn () => layaRespondingWith(500, ['detail' => 'inference failed'])->predictMany(['x'], questions()))
        ->toThrow(fn (ServerException $e) => expect($e->getMessage())->toContain('inference failed')->and($e->getPrevious())->toBeNull());
});

it('refuses a batch response without one result per state', function (array $body) {
    expect(fn () => layaRespondingWith(200, $body)->predictMany(['a', 'b'], questions()))
        ->toThrow(fn (ServerException $e) => expect($e->status)->toBe(200));
})->with([
    'no results' => [['answers' => []]],
    'not a list' => [['results' => ['a' => LAYA_RESPONSE, 'b' => LAYA_RESPONSE]]],
    'too few' => [['results' => [LAYA_RESPONSE]]],
    'not an object' => [['results' => [LAYA_RESPONSE, 'garbage']]],
]);

it('does not cache a batch result that is not laya-shaped', function () {
    $cache = new Repository(new ArrayStore);
    $laya = new Laya(httpClient: new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return new Response(200, [], json_encode(['results' => [['detail' => 'ok']]]));
        }
    }, cache: $cache);

    expect(fn () => $laya->predictMany(['x'], questions()))->toThrow(ServerException::class)
        ->and($cache->getStore()->all())->toBe([]);
});

it('decides many states into instances, keeping their keys', function () {
    $sent = [];
    $decisions = layaBatching($sent)->decideMany(['t-7' => 'Cancel now', 't-8' => 'Thanks'], BatchTriage::class, Model::English);

    expect(array_keys($decisions))->toBe(['t-7', 't-8'])
        ->and($decisions['t-7'])->toBeInstanceOf(BatchTriage::class)
        ->and($decisions['t-7']->churn)->toBeTrue()
        ->and(json_decode((string) $sent[0]->getBody(), true)['model'])->toBe('english');
});

it('records each state of a faked batch as its own prediction', function () {
    $laya = Laya::fake(['churn' => true]);

    $results = $laya->predictMany(['a' => 'Cancel now', 'b' => 'Thanks'], ['churn' => Question::yesNo('Cancel?')], Model::English);
    $decisions = $laya->decideMany(['Cancel now'], BatchTriage::class);

    expect($results['b']->yesNo('churn')->yes())->toBeTrue()
        ->and($results['b']->routedModel)->toBe('english')
        ->and($decisions[0]->churn)->toBeTrue();
    $laya->assertPredictedCount(3);
    $laya->assertPredicted(fn ($state, $questions, $model) => $state === 'Thanks' && $model === 'english');
    $laya->assertDecided(BatchTriage::class, fn ($state) => $state === 'Cancel now');
});
