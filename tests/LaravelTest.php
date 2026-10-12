<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MarcReichel\Laya\Events\PredictionFailed;
use MarcReichel\Laya\Events\PredictionMade;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laravel\EventDispatcher;
use MarcReichel\Laya\Laravel\LayaServiceProvider;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;
use MarcReichel\Laya\Testing\FakeHttpClient;
use Orchestra\Testbench\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\RetryableHttpClient;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(LayaServiceProvider::class);
});

it('resolves a configured singleton', function () {
    config(['laya.url' => 'http://laya.internal:8000/', 'laya.api_key' => 'secret']);

    $laya = app(Laya::class);

    expect($laya)->toBe(app(Laya::class));

    $url = (fn () => $this->baseUrl)->call($laya);
    $apiKey = (fn () => $this->apiKey)->call($laya);
    expect($url)->toBe('http://laya.internal:8000')->and($apiKey)->toBe('secret');
});

it('batches by the configured size', function () {
    expect(config('laya.batch_size'))->toBe(64);

    config(['laya.batch_size' => 8]);

    expect((fn () => $this->batchSize)->call(app(Laya::class)))->toBe(8);
});

it('treats an empty api key as none', function () {
    config(['laya.api_key' => '']);

    expect((fn () => $this->apiKey)->call(app(Laya::class)))->toBeNull();
});

it('merges the default config', function () {
    expect(config('laya.url'))->toBe('http://localhost:8000');
});

it('swaps the container binding for the fake', function () {
    app(Laya::class); // resolve the real one first, as a booted app would

    $fake = Laya::fake(['churn' => true]);

    $result = app(Laya::class)->predict('Cancel my plan.', ['churn' => Question::yesNo('Threatens to cancel?')]);

    expect(app(Laya::class))->toBe($fake)
        ->and($result->yesNo('churn')->yes())->toBeTrue();
    $fake->assertPredictedCount(1);
});

it('keeps the swapped fake when rules and sequences are chained on', function () {
    $fake = Laya::fake(['churn' => true])->when(fn ($state) => $state === 'Stay.', ['churn' => false])->sequence(['churn' => 0.4]);

    $first = app(Laya::class)->predict('Cancel my plan.', ['churn' => Question::yesNo('Threatens to cancel?')]);
    $stay = app(Laya::class)->predict('Stay.', ['churn' => Question::yesNo('Threatens to cancel?')]);

    expect(app(Laya::class))->toBe($fake)
        ->and($first->yesNo('churn')->probability)->toBe(0.4)
        ->and($stay->yesNo('churn')->no())->toBeTrue();
});

it('publishes the config file under the laya-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(LayaServiceProvider::class, 'laya-config');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../config/laya.php')])
        ->and(array_values($paths))->toBe([config_path('laya.php')]);
});

it('caches predictions in the configured store for the configured ttl', function () {
    config(['laya.cache.store' => 'array', 'laya.cache.ttl' => '60']);

    $laya = app(Laya::class);
    $cache = (fn () => $this->cache)->call($laya);

    expect($cache)->toBe(Cache::store('array'))
        ->and((fn () => $this->cacheTtl)->call($laya))->toBe(60);
});

it('does not cache by default, or with an empty store', function () {
    config(['laya.cache.store' => '']);
    $laya = app(Laya::class);

    expect((fn () => $this->cache)->call($laya))->toBeNull()
        ->and((fn () => $this->cacheTtl)->call($laya))->toBeNull();
});

it('dispatches events through the app\'s dispatcher, without the state, by default', function () {
    $laya = app(Laya::class);

    expect((fn () => $this->events)->call($laya))->toBeInstanceOf(EventDispatcher::class)
        ->and((fn () => $this->includeState)->call($laya))->toBeFalse();
});

it('includes the state or turns events off as configured', function (mixed $enabled, mixed $includeState, bool $dispatches, bool $includes) {
    config(['laya.events.enabled' => $enabled, 'laya.events.include_state' => $includeState]);
    $laya = app(Laya::class);

    expect((fn () => $this->events)->call($laya) !== null)->toBe($dispatches)
        ->and((fn () => $this->includeState)->call($laya))->toBe($includes);
})->with([
    'off' => [false, true, false, true],
    'off, from .env' => ['false', null, false, false],
    'with the state, from .env' => ['true', '1', true, true],
]);

it('lets Event::fake() and Event::listen() see the events, even when faked after Laya was resolved', function () {
    $events = (fn () => $this->events)->call(app(Laya::class));
    $laya = layaRespondingWith(200, LAYA_RESPONSE, events: $events);
    $heard = [];
    Event::listen(PredictionMade::class, function (PredictionMade $event) use (&$heard) {
        $heard[] = $event;
    });

    $laya->predict('Billed twice', questions());
    Event::fake();
    $laya->predict('Billed again', questions());
    expect(fn () => layaRespondingWith(500, ['detail' => 'boom'], events: $events)->predict('x', questions()))->toThrow(ServerException::class);

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->routedModel)->toBe('english');
    Event::assertDispatchedTimes(PredictionMade::class, 1);
    Event::assertDispatched(PredictionFailed::class, fn (PredictionFailed $event) => $event->exception->getMessage() === 'laya-serve: boom (HTTP 500)');
});

it('dispatches the fake\'s events through the app\'s dispatcher, as configured', function () {
    config(['laya.events.include_state' => true]);
    Event::fake();

    Laya::fake(['churn' => true]);
    app(Laya::class)->predict('Cancel my plan.', ['churn' => Question::yesNo('Threatens to cancel?')]);

    Event::assertDispatched(PredictionMade::class, fn (PredictionMade $event) => $event->state === 'Cancel my plan.' && $event->questionIds === ['churn']);
});

it('dispatches nothing from the fake when events are off', function () {
    config(['laya.events.enabled' => false]);
    Event::fake();

    Laya::fake(['churn' => true])->predict('Cancel my plan.', ['churn' => Question::yesNo('Threatens to cancel?')]);

    Event::assertNotDispatched(PredictionMade::class);
});

it('lets a fake\'s own dispatcher and include_state win over the app\'s', function () {
    config(['laya.events.include_state' => true]);
    Event::fake();
    $events = new RecordingDispatcher;

    Laya::fake(['churn' => true], events: $events, includeState: false)->predict('Cancel my plan.', ['churn' => Question::yesNo('Threatens to cancel?')]);

    Event::assertNotDispatched(PredictionMade::class);
    expect($events->events)->toHaveCount(1)
        ->and($events->events[0]->state)->toBeNull();
});

it('returns the event it dispatched, as PSR-14 asks', function () {
    $event = new stdClass;

    expect(new EventDispatcher(app())->dispatch($event))->toBe($event);
});

it('reports laya-serve health on the command line', function () {
    app()->instance(Laya::class, Laya::fake());

    $this->artisan('laya:health')
        ->expectsOutputToContain('Status')
        ->expectsOutputToContain('fake')
        ->expectsOutputToContain('none')
        ->doesntExpectOutputToContain('unhealthy')
        ->doesntExpectOutputToContain('Idle')
        ->assertSuccessful();
});

it('shows the idle-unload window on the command line', function () {
    app()->instance(Laya::class, layaRespondingWith(200, ['status' => 'ok', 'loaded' => [], 'device' => 'cpu', 'idle_unload_seconds' => 600, 'idle_seconds' => 712.5]));

    expect(Artisan::call('laya:health'))->toBe(0)
        ->and(Artisan::output())->toMatch('/Idle unload after\b.*\b600s\b/')->toMatch('/Idle for\b.*\b712\.5s\b/');
});

it('fails the health command when laya-serve is unhealthy or unreachable', function (int $status, array|string $body, string $output) {
    app()->instance(Laya::class, layaRespondingWith($status, $body));

    $this->artisan('laya:health')->expectsOutputToContain($output)->assertFailed();
})->with([
    'unhealthy' => [200, ['status' => 'loading', 'loaded' => ['english', 'multilingual'], 'device' => 'cpu'], 'unhealthy'],
    'loaded checkpoints' => [200, ['status' => 'loading', 'loaded' => ['english', 'multilingual'], 'device' => 'cpu'], 'english, multilingual'],
    'error' => [500, ['detail' => 'boom'], 'laya-serve: boom (HTTP 500)'],
]);

function prop(object $object, string $name): mixed
{
    return (fn () => $this->$name)->call($object);
}

function layaHttp(): object
{
    return prop(app(Laya::class), 'http');
}

it('keeps the discovered http client when no client setting is given', function () {
    expect(prop(layaHttp(), 'client'))->not->toBeInstanceOf(RetryableHttpClient::class);
});

it('rejects a laya.http_client that is not a PSR-18 client', function () {
    app()->instance('my.client', new stdClass);
    config(['laya.http_client' => 'my.client']);

    app(Laya::class);
})->throws(RuntimeException::class, 'laya.http_client "my.client" is not');

it('uses the client named in laya.http_client', function () {
    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new LogicException('unused');
        }
    };
    app()->instance('my.client', $client);
    config(['laya.http_client' => 'my.client', 'laya.timeout' => 5]);

    expect(layaHttp())->toBe($client);
});

it('uses an explicit ClientInterface binding over the settings', function () {
    $client = new FakeHttpClient([]);
    app()->instance(ClientInterface::class, $client);
    config(['laya.retries' => 3]);

    expect(layaHttp())->toBe($client);
});

it('keeps the fake when a client is bound', function () {
    app()->instance(ClientInterface::class, new FakeHttpClient([]));
    app(Laya::class);

    expect(Laya::fake(['churn' => true]))->toBe(app(Laya::class));
});

it('builds a retrying client from laya.retries and laya.timeout', function () {
    config(['laya.timeout' => '2.5', 'laya.retries' => '4']);

    $http = layaHttp();
    $retrying = prop($http, 'client');

    expect($http)->toBeInstanceOf(Psr18Client::class)
        ->and($retrying)->toBeInstanceOf(RetryableHttpClient::class)
        ->and(prop($retrying, 'maxRetries'))->toBe(4)
        ->and(prop(prop($retrying, 'strategy'), 'statusCodes'))->toBe([503])
        ->and(prop(prop($retrying, 'client'), 'defaultOptions')['timeout'])->toBe(2.5);
});

it('only sets a timeout when retries are off', function (mixed $retries) {
    config(['laya.timeout' => 3, 'laya.retries' => $retries]);

    $client = prop(layaHttp(), 'client');

    expect($client)->not->toBeInstanceOf(RetryableHttpClient::class)
        ->and(prop($client, 'defaultOptions')['timeout'])->toBe(3.0);
})->with([null, 0]);

it('only retries when no timeout is set', function () {
    config(['laya.retries' => 1]);

    expect(prop(layaHttp(), 'client'))->toBeInstanceOf(RetryableHttpClient::class);
});

function withoutSymfony(): void
{
    $provider = new class(app()) extends LayaServiceProvider
    {
        protected function symfonyInstalled(): bool
        {
            return false;
        }
    };
    app()->forgetInstance(Laya::class);
    $provider->register();
}

it('explains that symfony/http-client is needed for the settings', function () {
    withoutSymfony();
    config(['laya.retries' => 2]);

    app(Laya::class);
})->throws(RuntimeException::class, 'symfony/http-client');

it('does not need symfony/http-client when no setting is given', function (mixed $empty) {
    withoutSymfony();
    config(['laya.timeout' => $empty, 'laya.retries' => $empty, 'laya.http_client' => $empty]);

    expect(app(Laya::class))->toBeInstanceOf(Laya::class);
})->with([null, '']);
