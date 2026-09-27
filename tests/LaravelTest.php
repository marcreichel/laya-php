<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
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
