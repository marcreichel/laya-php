<?php

declare(strict_types=1);

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
use Orchestra\Testbench\TestCase;

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
        ->assertSuccessful();
});

it('fails the health command when laya-serve is unhealthy or unreachable', function (int $status, array|string $body, string $output) {
    app()->instance(Laya::class, layaRespondingWith($status, $body));

    $this->artisan('laya:health')->expectsOutputToContain($output)->assertFailed();
})->with([
    'unhealthy' => [200, ['status' => 'loading', 'loaded' => ['english', 'multilingual'], 'device' => 'cpu'], 'unhealthy'],
    'loaded checkpoints' => [200, ['status' => 'loading', 'loaded' => ['english', 'multilingual'], 'device' => 'cpu'], 'english, multilingual'],
    'error' => [500, ['detail' => 'boom'], 'laya-serve: boom (HTTP 500)'],
]);
