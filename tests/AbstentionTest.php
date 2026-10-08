<?php

declare(strict_types=1);

use App\Decisions\Triage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use MarcReichel\Laya\Abstention;
use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\ScoreAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;
use MarcReichel\Laya\Exceptions\InvalidOptionException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

require_once __DIR__.'/Fixtures/Decisions.php';

it('sends min_confidence only when set', function () {
    $sent = [];
    $laya = layaRespondingWith(200, LAYA_RESPONSE, $sent);
    $laya->predict('Billed twice', questions(), minConfidence: 0.7);
    $laya->predict('Billed twice', questions(), minConfidence: ['choice:3-5' => 0.6, 'default' => 0.8]);
    $laya->predict('Billed twice', questions());

    expect(json_decode((string) $sent[0]->getBody(), true)['min_confidence'])->toBe(0.7)
        ->and((string) $sent[1]->getBody())->toContain('"min_confidence":{"choice:3-5":0.6,"default":0.8}')
        ->and(json_decode((string) $sent[2]->getBody(), true))->not->toHaveKey('min_confidence');
});

it('sends min_confidence for every state of a batch, and caches gated predictions apart', function () {
    $sent = [];
    $laya = layaBatching($sent, new Repository(new ArrayStore));
    $laya->predictMany(['a', 'b'], questions(), minConfidence: 0.5);
    $laya->predictMany(['a', 'b'], questions(), minConfidence: 0.5);
    $laya->predictMany(['a'], questions(), minConfidence: ['default' => 0.5]);
    $laya->predict('a', questions());

    expect($sent)->toHaveCount(3)
        ->and(json_decode((string) $sent[0]->getBody(), true))->toMatchArray(['states' => ['a', 'b'], 'min_confidence' => 0.5])
        ->and(json_decode((string) $sent[1]->getBody(), true))->toMatchArray(['states' => ['a'], 'min_confidence' => ['default' => 0.5]])
        ->and(json_decode((string) $sent[2]->getBody(), true))->not->toHaveKey('min_confidence');
});

it('passes min_confidence through decide() and decideMany()', function () {
    $sent = [];
    $laya = layaBatching($sent);
    $laya->decide('Billed twice', Triage::class, minConfidence: 0.4);
    $laya->decideMany(['Billed twice'], Triage::class, minConfidence: ['noul:2' => 0.9]);

    expect(json_decode((string) $sent[0]->getBody(), true)['min_confidence'])->toBe(0.4)
        ->and(json_decode((string) $sent[1]->getBody(), true)['min_confidence'])->toBe(['noul:2' => 0.9]);
});

it('accepts thresholds from 0 to 1 and every bucket laya-serve knows', function () {
    $sent = [];
    $laya = layaRespondingWith(200, LAYA_RESPONSE, $sent);
    $buckets = [];
    foreach (['choice', 'score', 'noul'] as $type) {
        foreach (['2', '3-5', '6-10', '11+'] as $size) {
            $buckets["$type:$size"] = 0.5;
        }
    }
    $laya->predict('x', questions(), minConfidence: 0.0);
    $laya->predict('x', questions(), minConfidence: 1.0);
    $laya->predict('x', questions(), minConfidence: 1);
    $laya->predict('x', questions(), minConfidence: $buckets + ['default' => 0]);
    $laya->predict('x', questions(), minConfidence: ['default' => 1]);

    expect($sent)->toHaveCount(5)
        ->and(json_decode((string) $sent[3]->getBody(), true)['min_confidence'])->toHaveCount(13);
});

it('refuses a minConfidence laya-serve would refuse, before sending anything', function (float|array $minConfidence, string $message) {
    $sent = [];
    $laya = layaRespondingWith(200, LAYA_RESPONSE, $sent);

    expect(fn () => $laya->predict('x', questions(), minConfidence: $minConfidence))->toThrow(InvalidOptionException::class, $message)
        ->and(fn () => $laya->predictMany(['x'], questions(), minConfidence: $minConfidence))->toThrow(InvalidOptionException::class, $message)
        ->and($sent)->toBe([]);
})->with([
    'below 0' => [-0.1, 'minConfidence must be a number from 0 to 1, got -0.1.'],
    'above 1' => [1.5, 'minConfidence must be a number from 0 to 1, got 1.5.'],
    'NaN' => [NAN, 'minConfidence must be a number from 0 to 1, got NAN.'],
    'infinite' => [INF, 'minConfidence must be a number from 0 to 1, got INF.'],
    'empty map' => [[], 'A minConfidence map needs at least one bucket.'],
    'list' => [[0.5], 'minConfidence has no bucket "0".'],
    'unknown type' => [['yesno:2' => 0.5], 'minConfidence has no bucket "yesno:2".'],
    'unknown size' => [['choice:3' => 0.5], 'minConfidence has no bucket "choice:3".'],
    'prefixed' => [['xchoice:2' => 0.5], 'minConfidence has no bucket "xchoice:2".'],
    'suffixed' => [['default2' => 0.5], 'minConfidence has no bucket "default2".'],
    'trailing newline' => [["noul:2\n" => 0.5], 'minConfidence has no bucket "noul:2'],
    'value above 1' => [['choice:2' => 2], 'minConfidence["choice:2"] must be a number from 0 to 1, got 2.'],
    'value below 0' => [['default' => -1], 'minConfidence["default"] must be a number from 0 to 1, got -1.'],
    'NaN value' => [['default' => NAN], 'minConfidence["default"] must be a number from 0 to 1, got NAN.'],
    'string value' => [['default' => '0.5'], 'minConfidence["default"] must be a number from 0 to 1, got \'0.5\'.'],
    'bool value' => [['default' => true], 'minConfidence["default"] must be a number from 0 to 1, got true.'],
    'null value' => [['default' => null], 'minConfidence["default"] must be a number from 0 to 1, got NULL.'],
]);

it('reads the abstention report from an answer', function () {
    $abstained = ChoiceAnswer::fromArray('d', ['choice' => 'a', 'answer_confidence' => 0.4, 'abstention' => 'abstained', 'abstention_threshold' => 0.6, 'low_confidence' => true]);
    $passed = ScoreAnswer::fromArray('u', ['score' => 1.0, 'abstention' => 'passed', 'abstention_threshold' => '0.25']);
    $unevaluated = YesNoAnswer::fromArray('c', ['abstention' => 'unevaluated', 'abstention_threshold' => 0]);

    expect($abstained->abstention)->toBe(Abstention::Abstained)
        ->and($abstained->abstentionThreshold)->toBe(0.6)
        ->and($abstained->lowConfidence)->toBeTrue()
        ->and($passed->abstention)->toBe(Abstention::Passed)
        ->and($passed->abstentionThreshold)->toBe(0.25)
        ->and($passed->lowConfidence)->toBeFalse()
        ->and($unevaluated->abstention)->toBe(Abstention::Unevaluated)
        ->and($unevaluated->abstentionThreshold)->toBe(0.0);
});

it('reports no abstention when no gate ran, and ignores a malformed report', function () {
    $ungated = YesNoAnswer::fromArray('c', ['noul' => 0.8]);
    $malformed = YesNoAnswer::fromArray('c', ['noul' => 0.8, 'abstention' => 1, 'abstention_threshold' => 'n/a', 'low_confidence' => 'true']);

    expect($ungated->abstention)->toBeNull()
        ->and($ungated->abstentionThreshold)->toBeNull()
        ->and($ungated->lowConfidence)->toBeFalse()
        ->and($malformed->abstention)->toBeNull()
        ->and($malformed->abstentionThreshold)->toBeNull()
        ->and($malformed->lowConfidence)->toBeFalse()
        ->and(YesNoAnswer::fromArray('c', ['abstention' => 'skipped'])->abstention)->toBeNull();
});

it('fakes the abstention report of a gated request', function () {
    $laya = Laya::fake(['dept' => 'billing', 'churn' => 0.3, 'unsure' => null]);
    $questions = [
        'dept' => Question::choice('Dept?', ['billing', 'other']),
        'churn' => Question::yesNo('Cancel?'),
        'unsure' => Question::yesNo('Unsure?'),
    ];
    $gated = $laya->predict('x', $questions, minConfidence: 0.75);
    $ungated = $laya->predict('x', $questions);

    expect($gated->raw['answers'])->toBe([
        'dept' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 1.0, 'answer_confidence' => 1.0, 'probabilities' => ['billing' => 1.0, 'other' => 0.0],
            'abstention' => 'passed', 'abstention_threshold' => 0.75],
        'churn' => ['type' => 'noul', 'noul' => 0.3, 'confidence' => 0.7, 'answer_confidence' => 0.7,
            'low_confidence' => true, 'abstention' => 'abstained', 'abstention_threshold' => 0.75],
        'unsure' => ['type' => 'noul', 'noul' => 0.5, 'confidence' => 0.0, 'answer_confidence' => 0.0,
            'low_confidence' => true, 'abstention' => 'abstained', 'abstention_threshold' => 0.75],
    ])
        ->and($gated->yesNo('churn')->lowConfidence)->toBeTrue()
        ->and($gated->choice('dept')->abstention)->toBe(Abstention::Passed)
        ->and($ungated->choice('dept')->raw)->not->toHaveKeys(['abstention', 'abstention_threshold', 'low_confidence']);
});

it('passes a fake answer whose confidence equals the threshold', function () {
    $laya = Laya::fake(['sure' => true, 'unsure' => null]);
    $questions = ['sure' => Question::yesNo('Sure?'), 'unsure' => Question::yesNo('Unsure?')];

    expect($laya->predict('x', ['sure' => $questions['sure']], minConfidence: 1.0)->yesNo('sure')->raw)->toMatchArray(['abstention' => 'passed', 'abstention_threshold' => 1.0])
        ->and($laya->predict('x', ['unsure' => $questions['unsure']], minConfidence: 0.0)->yesNo('unsure')->raw)->toMatchArray(['abstention' => 'passed', 'abstention_threshold' => 0.0])
        ->and($laya->predict('x', ['sure' => $questions['sure']], minConfidence: ['noul:2' => 1])->yesNo('sure')->raw['abstention_threshold'])->toBe(1.0);
});

it('fakes a per-bucket gate by type and option count', function (int $options, string $bucket) {
    $labels = array_map(fn (int $i) => "o$i", range(1, $options));
    $levels = array_map(fn (int $i) => "level $i", range(1, $options));
    $thresholds = ['choice:2' => 0.1, 'choice:3-5' => 0.2, 'choice:6-10' => 0.3, 'choice:11+' => 0.4,
        'score:2' => 0.5, 'score:3-5' => 0.6, 'score:6-10' => 0.7, 'score:11+' => 0.8];

    $result = Laya::fake(['c' => 'o1', 's' => 0])->predict('x', [
        'c' => Question::choice('Which?', $labels),
        's' => Question::score('How much?', $levels),
    ], minConfidence: $thresholds);

    expect($result->choice('c')->abstentionThreshold)->toBe($thresholds["choice:$bucket"])
        ->and($result->score('s')->abstentionThreshold)->toBe($thresholds["score:$bucket"]);
})->with([
    [1, '2'], [2, '2'], [3, '3-5'], [5, '3-5'], [6, '6-10'], [10, '6-10'], [11, '11+'],
]);

it('falls back to the map\'s default, then to no gate, for a bucket the map does not name', function () {
    $laya = Laya::fake(['churn' => null]);
    $question = ['churn' => Question::yesNo('Cancel?')];

    expect($laya->predict('x', $question, minConfidence: ['choice:2' => 0.9, 'default' => 0.3])->yesNo('churn')->raw)->toMatchArray(['abstention' => 'abstained', 'abstention_threshold' => 0.3])
        ->and($laya->predict('x', $question, minConfidence: ['noul:2' => 0.2, 'default' => 0.3])->yesNo('churn')->abstentionThreshold)->toBe(0.2)
        ->and($laya->predict('x', $question, minConfidence: ['choice:2' => 0.9])->yesNo('churn')->raw)->toMatchArray(['abstention' => 'passed', 'abstention_threshold' => 0.0]);
});

it('fakes the abstention report for every state of a batch', function () {
    $results = Laya::fake(['churn' => 0.3])->predictMany(['a', 'b'], ['churn' => Question::yesNo('Cancel?')], minConfidence: 0.9);

    expect($results[0]->yesNo('churn')->abstention)->toBe(Abstention::Abstained)
        ->and($results[1]->yesNo('churn')->lowConfidence)->toBeTrue();
});
