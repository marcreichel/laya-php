<?php

declare(strict_types=1);

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
