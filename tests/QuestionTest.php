<?php

declare(strict_types=1);

use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Question;
use MarcReichel\Laya\QuestionType;

it('serialises choice questions from a list or a map as a JSON object', function () {
    expect(json_encode(Question::choice('Dept?', ['billing', 'other'])->toArray()))
        ->toBe('{"type":"choice","instructions":"Dept?","criteria":{"billing":null,"other":null}}')
        ->and(json_encode(Question::choice('Dept?', ['billing' => 'refunds'])->toArray()))
        ->toBe('{"type":"choice","instructions":"Dept?","criteria":{"billing":"refunds"}}');
});

it('serialises score questions as a list of levels', function () {
    expect(Question::score('Urgent?', ['low', 'high'])->toArray())
        ->toBe(['type' => 'score', 'instructions' => 'Urgent?', 'criteria' => ['low', 'high']]);
});

it('serialises yes/no questions as noul, with optional true/false descriptions', function () {
    expect(Question::yesNo('Cancel?')->toArray())->toBe(['type' => 'noul', 'instructions' => 'Cancel?'])
        ->and(json_encode(Question::yesNo('Cancel?', yes: 'leaving')->toArray()))
        ->toBe('{"type":"noul","instructions":"Cancel?","criteria":{"true":"leaving"}}');
});

it('rejects malformed questions before sending them', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidQuestionException::class, $message);
})->with([
    'empty instructions' => [fn () => Question::yesNo('  '), 'needs instructions'],
    'no choice options' => [fn () => Question::choice('Dept?', []), 'at least one option'],
    'non-scalar label' => [fn () => Question::choice('Dept?', [['nested']]), 'Choice label 0'],
    'no score levels' => [fn () => Question::score('Urgent?', []), 'non-empty list'],
    'non-string level' => [fn () => Question::score('Urgent?', ['low', null]), 'Score level 1'],
    'non-string description' => [fn () => Question::choice('Dept?', ['billing' => 5]), 'choice option "billing"'],
    'bad yes/no criteria' => [fn () => new Question(QuestionType::YesNo, 'Cancel?', ['maybe' => 'unsure']), "keyed 'true'/'false'"],
]);
