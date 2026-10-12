<?php

declare(strict_types=1);

use App\Decisions\Triage;
use Illuminate\Support\Facades\Validator;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laravel\LayaServiceProvider;
use MarcReichel\Laya\Laravel\Rules\Decides;
use MarcReichel\Laya\Laya;
use Orchestra\Testbench\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

uses(TestCase::class);

require_once __DIR__.'/Fixtures/Decisions.php';

beforeEach(function () {
    $this->app->register(LayaServiceProvider::class);
});

function validates(mixed $value, Decides $rule, array $rules = []): Illuminate\Validation\Validator
{
    return Validator::make(['body' => $value], ['body' => [...$rules, $rule]]);
}

it('passes or fails on the decision object', function (bool $churn, bool $passes) {
    $laya = Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => $churn]);

    $rule = Decides::that(Triage::class, fn (Triage $t) => ! $t->churn);

    expect(validates('Billed twice', $rule)->passes())->toBe($passes);
    $laya->assertDecided(Triage::class);
})->with([[false, true], [true, false]]);

it('answers yes/no shorthands', function () {
    Laya::fake(['answer' => true]);
    expect(validates('You idiot', Decides::not('Insulting?'))->passes())->toBeFalse()
        ->and(validates('You idiot', Decides::yes('Insulting?'))->passes())->toBeTrue();

    Laya::fake(['answer' => false]);
    expect(validates('Nice', Decides::not('Insulting?'))->passes())->toBeTrue()
        ->and(validates('Nice', Decides::yes('Insulting?'))->passes())->toBeFalse();
});

it('fails with the default message, translated and with the attribute', function () {
    Laya::fake(['answer' => true]);

    expect(validates('x', Decides::not('Insulting?'))->errors()->first('body'))->toBe('The body is not acceptable.');

    app()->setLocale('de');
    expect(validates('x', Decides::not('Insulting?'))->errors()->first('body'))->toBe('body ist nicht zulässig.');
});

it('prefers the app\'s validation.laya', function () {
    Laya::fake(['answer' => true]);
    app('translator')->addLines(['validation.laya' => 'Custom :attribute'], 'en');

    expect(validates('x', Decides::not('Insulting?'))->errors()->first('body'))->toBe('Custom body');
});

it('takes a custom message', function () {
    Laya::fake(['answer' => true]);

    expect(validates('x', Decides::not('Insulting?')->message('Keep it friendly.'))->errors()->first('body'))->toBe('Keep it friendly.');
});

it('fails non-strings without a request', function () {
    $laya = Laya::fake(['answer' => false]);

    expect(validates(['a'], Decides::not('Insulting?'))->passes())->toBeFalse();
    $laya->assertNothingPredicted();
});

it('fails or passes when laya is unavailable', function (int $status, bool $passWhenUnavailable) {
    $rule = Decides::not('Insulting?');
    app()->instance(Laya::class, layaRespondingWith($status, 'busy'));

    expect(validates('x', $passWhenUnavailable ? $rule->passWhenUnavailable() : $rule)->passes())->toBe($passWhenUnavailable);
})->with([[503, false], [503, true]]);

it('fails or passes when laya is unreachable', function (bool $passWhenUnavailable) {
    app()->instance(Laya::class, new Laya('http://laya.local', httpClient: new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('down') extends RuntimeException implements ClientExceptionInterface {};
        }
    }));
    $rule = Decides::not('Insulting?');

    expect(validates('x', $passWhenUnavailable ? $rule->passWhenUnavailable() : $rule)->passes())->toBe($passWhenUnavailable);
})->with([false, true]);

it('lets other laya errors through', function () {
    app()->instance(Laya::class, layaRespondingWith(500, 'boom'));

    validates('x', Decides::not('Insulting?')->passWhenUnavailable())->passes();
})->throws(ServerException::class);

it('ships a message with :attribute for every language', function () {
    foreach (glob(__DIR__.'/../lang/*/validation.php') as $file) {
        expect((require $file)['laya'])->toContain(':attribute');
    }
});
