<?php

declare(strict_types=1);

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

final readonly class Lead
{
    public function __construct(
        #[Ask('How strong is the buying intent?'), Levels('none', 'some', 'strong')] public float $intent,
        #[Ask('Is there budget?', yes: 'budget is approved', no: 'no budget yet')] public float $budget,
    ) {}
}

final readonly class UnsureLead
{
    public function __construct(
        #[Ask('How strong is the buying intent?', minConfidence: 0.5), Levels('none', 'strong')] public ?float $intent,
        #[Ask('Is there budget?', minConfidence: 0.5)] public ?float $budget,
    ) {}
}

it('asks a float with #[Levels] as a score question, and a float without as a yes/no question', function () {
    $laya = Laya::fake(new Lead(intent: 1.3, budget: 0.8));

    expect($laya->decide('x', Lead::class))->toEqual(new Lead(intent: 1.3, budget: 0.8));
    $laya->assertPredicted(fn ($state, array $questions) => $questions === [
        'intent' => ['type' => 'score', 'instructions' => 'How strong is the buying intent?', 'criteria' => ['none', 'some', 'strong']],
        'budget' => ['type' => 'noul', 'instructions' => 'Is there budget?', 'criteria' => ['true' => 'budget is approved', 'false' => 'no budget yet']],
    ]);
});

it('hydrates the expected level and P(yes), not the most likely level or a bool', function () {
    $laya = layaRespondingWith(200, ['answers' => [
        'intent' => ['type' => 'score', 'score' => 1.6, 'probabilities' => [0.1, 0.2, 0.7]],
        'budget' => ['type' => 'noul', 'noul' => 0.3],
    ]]);

    expect($laya->decide('x', Lead::class))->toEqual(new Lead(intent: 1.6, budget: 0.3));
});

it('hydrates null below minConfidence on a nullable float', function () {
    $laya = layaRespondingWith(200, ['answers' => [
        'intent' => ['type' => 'score', 'score' => 0.5, 'probabilities' => [0.5, 0.5], 'answer_confidence' => 0.4],
        'budget' => ['type' => 'noul', 'noul' => 0.55, 'answer_confidence' => 0.4],
    ]]);

    expect($laya->decide('x', UnsureLead::class))->toEqual(new UnsureLead(null, null));
});

it('rejects float parameters it cannot ask', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
})->with([
    'threshold on a score' => [new class(1.0)
    {
        public function __construct(#[Ask('Intent?', threshold: 0.4), Levels('a', 'b')] public float $f) {}
    }, '::$f is neither a bool nor an array of enum cases, so #[Ask] can\'t take threshold.'],
    'threshold on a yes/no' => [new class(1.0)
    {
        public function __construct(#[Ask('Budget?', threshold: 0.4)] public float $f) {}
    }, '::$f is neither a bool nor an array of enum cases, so #[Ask] can\'t take threshold.'],
    'yes on a score' => [new class(1.0)
    {
        public function __construct(#[Ask('Intent?', yes: 'x'), Levels('a', 'b')] public float $f) {}
    }, '::$f is not a bool, so #[Ask] can\'t take yes or no.'],
    'minConfidence on a non-nullable' => [new class(1.0)
    {
        public function __construct(#[Ask('Budget?', minConfidence: 0.5)] public float $f) {}
    }, 'must be nullable (?float)'],
]);

it('fakes an expected level by splitting it between its two neighbouring levels', function (float|int $value, array $probabilities) {
    $answer = Laya::fake(['u' => $value])->predict('x', ['u' => Question::score('Urgent?', ['low', 'mid', 'high'])])->score('u');

    expect($answer->score)->toBe((float) $value)
        ->and($answer->probabilities)->toEqualWithDelta($probabilities, 1e-9);
})->with([
    'between' => [1.3, [0.0, 0.7, 0.3]],
    'lowest' => [0, [1.0, 0.0, 0.0]],
    'highest' => [2.0, [0.0, 0.0, 1.0]],
]);

it('fails loudly on a fake score outside the levels', function (mixed $value) {
    expect(fn () => Laya::fake(['u' => $value])->predict('x', ['u' => Question::score('Urgent?', ['low', 'high'])]))
        ->toThrow(LogicException::class, 'Fake answer for score question "u" must be a level index from 0 to 1, or an expected level in between.');
})->with([-0.1, 1.1, '1']);
