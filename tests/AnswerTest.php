<?php

declare(strict_types=1);

use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\ScoreAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;

it('reads numbers leniently and defaults what is missing to 0.0', function () {
    $answer = ChoiceAnswer::fromArray('d', ['choice' => 'a', 'probabilities' => ['a' => '0.75', 'b' => 'n/a'], 'confidence' => '0.5', 'answer_confidence' => '0.6']);
    $empty = ChoiceAnswer::fromArray('d', ['choice' => 'a', 'probabilities' => 'garbage']);

    expect($answer->probabilities)->toBe(['a' => 0.75, 'b' => 0.0])
        ->and($answer->confidence)->toBe(0.5)
        ->and($answer->answerConfidence)->toBe(0.6)
        ->and($empty->probabilities)->toBe([0 => 0.0])
        ->and($empty->confidence)->toBe(0.0)
        ->and($empty->answerConfidence)->toBe(0.0);
});

it('falls back to confidence when the server sends no answer_confidence, as TypeSafe\'s spec does', function () {
    $choice = ChoiceAnswer::fromArray('d', ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 0.988, 'other' => 0.012], 'confidence' => 0.934]);
    $score = ScoreAnswer::fromArray('u', ['type' => 'score', 'score' => 1.5, 'probabilities' => ['0' => 0.0, '1' => 0.5, '2' => 0.5], 'confidence' => 0.3691]);

    expect($choice->answerConfidence)->toBe(0.934)
        ->and($score->answerConfidence)->toBe(0.3691)
        ->and(ChoiceAnswer::fromArray('d', ['choice' => 'a', 'confidence' => 0.4, 'answer_confidence' => 'n/a'])->answerConfidence)->toBe(0.4)
        ->and(ChoiceAnswer::fromArray('d', ['choice' => 'a', 'confidence' => 0.4, 'answer_confidence' => 0])->answerConfidence)->toBe(0.0);
});

it('derives a yes/no confidence from the probability when the server sends only the probability', function () {
    $no = YesNoAnswer::fromArray('c', ['type' => 'noul', 'noul' => 0.3]);
    $yes = YesNoAnswer::fromArray('c', ['type' => 'noul', 'noul' => 0.8]);

    expect($no->confidence)->toBe(0.7)
        ->and($no->answerConfidence)->toBe(0.7)
        ->and($yes->confidence)->toBe(0.8)
        ->and($yes->answerConfidence)->toBe(0.8)
        ->and(YesNoAnswer::fromArray('c', ['noul' => 0.8, 'confidence' => '0.25'])->answerConfidence)->toBe(0.25)
        ->and(YesNoAnswer::fromArray('c', ['noul' => 0.8, 'confidence' => 'n/a'])->confidence)->toBe(0.8);
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
