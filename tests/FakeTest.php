<?php

declare(strict_types=1);

use Illuminate\Container\Container;
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
    Container::setInstance(null);
});
