<?php

declare(strict_types=1);

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use PHPUnit\Framework\AssertionFailedError;

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

it('fails loudly on unregistered questions and impossible answers', function () {
    expect(fn () => Laya::fake()->predict('x', ['churn' => Question::yesNo('Cancel?')]))->toThrow(LogicException::class, 'no answer for question "churn"')
        ->and(fn () => Laya::fake(['d' => 'sales'])->predict('x', ['d' => Question::choice('Dept?', ['billing'])]))->toThrow(LogicException::class, 'not one of its options')
        ->and(fn () => Laya::fake(['u' => 5])->predict('x', ['u' => Question::score('Urgent?', ['low'])]))->toThrow(LogicException::class, 'level index')
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
