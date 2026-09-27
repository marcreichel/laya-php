<?php

declare(strict_types=1);

use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\ScoreAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;

it('reads numbers leniently and defaults what is missing to 0.0', function () {
    $answer = ChoiceAnswer::fromArray('d', ['choice' => 'a', 'probabilities' => ['a' => '0.75', 'b' => 'n/a'], 'confidence' => '0.5']);

    expect($answer->probabilities)->toBe(['a' => 0.75, 'b' => 0.0])
        ->and($answer->confidence)->toBe(0.5)
        ->and($answer->answerConfidence)->toBe(0.0)
        ->and(ChoiceAnswer::fromArray('d', ['choice' => 'a', 'probabilities' => 'garbage'])->probabilities)->toBe([0 => 0.0]);
});

it('compares choices loosely, since JSON turns numeric labels into strings', function () {
    expect(ChoiceAnswer::fromArray('d', ['choice' => 1])->is('1'))->toBeTrue()
        ->and(ChoiceAnswer::fromArray('d', ['choice' => '1'])->is(1))->toBeTrue()
        ->and(ChoiceAnswer::fromArray('d', ['choice' => '1'])->is(2))->toBeFalse();
});

it('reads a score answer, keeping only string levels and ordering probabilities by level', function () {
    $answer = ScoreAnswer::fromArray('u', [
        'score' => 1.2,
        'legend' => ['0' => 'low', '1' => 5, '2' => 'high'],
        'probabilities' => ['low' => 0.3, 'high' => 0.7],
        'confidence' => 0.4,
        'answer_confidence' => 0.6,
    ]);

    expect($answer->legend)->toBe(['low', 'high'])
        ->and($answer->probabilities)->toBe([0.3, 0.7])
        ->and($answer->confidence)->toBe(0.4)
        ->and($answer->answerConfidence)->toBe(0.6)
        ->and($answer->raw['score'])->toBe(1.2)
        ->and(ScoreAnswer::fromArray('u', ['legend' => 'garbage'])->legend)->toBe(['garbage'])
        ->and(ScoreAnswer::fromArray('u', ['score' => 1.2])->level())->toBe(1);
});

it('says yes from a probability of 0.5 up', function () {
    $answer = YesNoAnswer::fromArray('c', ['noul' => 0.5, 'confidence' => 0.4, 'answer_confidence' => 0.6]);

    expect($answer->yes())->toBeTrue()
        ->and($answer->confidence)->toBe(0.4)
        ->and($answer->answerConfidence)->toBe(0.6)
        ->and(YesNoAnswer::fromArray('c', ['noul' => 0.45])->yes())->toBeFalse()
        ->and(YesNoAnswer::fromArray('c', ['noul' => 0.55])->no())->toBeFalse();
});
