<?php

declare(strict_types=1);

use App\Decisions\Areas;
use App\Decisions\Coded;
use App\Decisions\Triage;
use GuzzleHttp\Psr7\Response;
use MarcReichel\Laya\Evaluation;
use MarcReichel\Laya\Exceptions\InvalidOptionException;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Testing\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__.'/Fixtures/Decisions.php';

it('measures each labelled question against the raw answers', function () {
    $report = new Evaluation(new Laya('http://laya.test', httpClient: triageClient()))->run(Triage::class, triageRows(), precision: 0.8);

    expect($report->render())->toBe(TRIAGE_REPORT);
});

it('compares labels with the ungated answer, ignoring #[Ask] minConfidence and threshold', function () {
    // Triage::$churn has minConfidence 0.85, which would turn the 0.7 answer into null.
    $laya = Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => 0.3]);

    $report = new Evaluation($laya)->run(Triage::class, [['state' => 'x', 'churn' => 'false']]);

    expect($report->parameters['churn']['accuracy'])->toBe(1.0)
        ->and($report->parameters['churn']['min_confidence'])->toBe(['value' => 0.7, 'precision' => 1.0, 'to_human' => 0.0]);
});

it('counts P(yes) of exactly 0.5 as yes', function () {
    $report = new Evaluation(Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => 0.5]))
        ->run(Triage::class, [['state' => 'x', 'churn' => 'true']]);

    expect($report->parameters['churn'])->toMatchArray(['accuracy' => 1.0, 'precision' => 1.0, 'recall' => 1.0]);
});

it('reports the JSON a CI job can read', function () {
    $report = new Evaluation(Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => true]))
        ->run(Triage::class, [['state' => 'x', 'urgency' => '0'], ['state' => 'y', 'urgency' => '']]);

    expect($report->toArray())->toBe([
        'class' => Triage::class,
        'rows' => 2,
        'precision' => 0.95,
        'parameters' => ['urgency' => $report->parameters['urgency']],
    ])->and($report->toJson())->toBe(<<<'JSON'
        {
            "class": "App\\Decisions\\Triage",
            "rows": 2,
            "precision": 0.95,
            "parameters": {
                "urgency": {
                    "type": "score",
                    "labelled": 1,
                    "accuracy": 0.0,
                    "confusion": {
                        "0": {
                            "0": 0,
                            "1": 1
                        },
                        "1": {
                            "0": 0,
                            "1": 0
                        }
                    },
                    "calibration": {
                        "0.9-1.0": {
                            "answers": 1,
                            "accuracy": 0.0
                        }
                    },
                    "min_confidence": null
                }
            }
        }
        JSON);
});

it('reports a recall of 0 and rounds the share sent to a human', function () {
    $laya = Laya::fake(['department' => 'billing', 'urgency' => 0])->sequence(['churn' => 0.35], ['churn' => 0.1], ['churn' => 0.05]);

    $report = new Evaluation($laya)->run(Triage::class, [
        ['state' => 'a', 'churn' => 'true'],
        ['state' => 'b', 'churn' => 'false'],
        ['state' => 'c', 'churn' => 'false'],
    ]);

    expect($report->render())->toBe(<<<'TXT'
        App\Decisions\Triage: 3 rows, target precision 0.95

        churn: 3 labelled
          accuracy 0.67, precision n/a, recall 0.00
          calibration (answer confidence: accuracy):
            0.6-0.7  0.00 of 1
            0.9-1.0  1.00 of 2
          minConfidence 0.9 reaches precision 1.00 and sends 33% to a human
          threshold 0.35 has the best F1 (1.00)
        TXT);
});

it('widens the confusion matrix for large counts', function () {
    $rows = array_map(fn (int $i) => ['state' => "s{$i}", 'urgency' => '1'], range(1, 10));

    $report = new Evaluation(Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => true]))->run(Triage::class, $rows);

    expect($report->render())->toContain(<<<'TXT'
          confusion (label down, answer across):
                 1
             1  10
        TXT);
});

it('says when no minConfidence reaches the precision, and scores #[Of] cases and unpredicted labels', function () {
    $laya = Laya::fake(['churn' => false])->sequence(
        ['areas.billing' => 0.1, 'areas.docs.api' => 0.9],
        ['areas.billing' => 0.3, 'areas.docs.api' => 0.2],
    );

    $report = new Evaluation($laya)->run(Areas::class, [
        ['state' => 'a', 'areas.billing' => false, 'areas.docs.api' => false],
        ['state' => 'b', 'areas.billing' => false, 'areas.docs.api' => false, 'churn' => null],
    ], precision: 1.0);

    expect($report->render())->toBe(<<<'TXT'
        App\Decisions\Areas: 2 rows, target precision 1.00

        areas.billing: 2 labelled
          accuracy 1.00, precision n/a, recall n/a
          calibration (answer confidence: accuracy):
            0.7-0.8  1.00 of 1
            0.9-1.0  1.00 of 1
          minConfidence 0.7 reaches precision 1.00 and sends 0% to a human
          threshold 0.1 has the best F1 (0.00)

        areas.docs.api: 2 labelled
          accuracy 0.50, precision 0.00, recall n/a
          calibration (answer confidence: accuracy):
            0.8-0.9  1.00 of 1
            0.9-1.0  0.00 of 1
          no minConfidence reaches precision 1.00
          threshold 0.2 has the best F1 (0.00)
        TXT);
});

it('takes JSON labels: ints, bools and nulls', function () {
    $laya = layaRespondingWith(200, ['results' => [['answers' => ['code' => ['type' => 'choice', 'choice' => 1, 'confidence' => 0.9]]]]]);

    $report = new Evaluation($laya)->run(Coded::class, [['state' => 'x', 'code' => 1]], precision: 0.0);

    expect($report->parameters['code'])->toMatchArray(['accuracy' => 1.0, 'confusion' => [1 => [1 => 1]]]);
});

it('trims labels', function () {
    $report = new Evaluation(Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => true]))
        ->run(Triage::class, [['state' => 'x', 'department' => ' billing ']]);

    expect($report->parameters['department']['accuracy'])->toBe(1.0);
});

it('asks one state at a time when the server has no batch endpoint', function () {
    $fake = new FakeHttpClient(['department' => 'billing', 'urgency' => 0, 'churn' => true]);
    $client = new class($fake) implements ClientInterface
    {
        public function __construct(private FakeHttpClient $fake) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return str_ends_with($request->getUri()->getPath(), '/batch') ? new Response(404, [], '{"detail": "Not Found"}') : $this->fake->sendRequest($request);
        }
    };

    $report = new Evaluation(new Laya('http://laya.test', httpClient: $client))
        ->run(Triage::class, [['state' => 'x', 'department' => 'billing'], ['state' => 'y', 'department' => 'other']]);

    expect($report->parameters['department']['accuracy'])->toBe(0.5)
        ->and($fake->requests)->toHaveCount(2);
});

it('passes on other server errors', function () {
    new Evaluation(layaRespondingWith(500, ['detail' => 'boom']))->run(Triage::class, [['state' => 'x']]);
})->throws(ServerException::class, 'boom');

it('accepts a precision from 0 to 1', function (float $precision) {
    expect(new Evaluation(Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => true]))->run(Triage::class, [['state' => 'x']], $precision)->precision)
        ->toBe($precision);
})->with([0.0, 1.0]);

it('refuses a precision outside 0 to 1', function (float $precision) {
    new Evaluation(Laya::fake())->run(Triage::class, [], $precision);
})->with([-0.1, 1.1])->throws(InvalidOptionException::class, 'precision must be a number from 0 to 1, got');

it('refuses what it cannot score', function (array $rows, string $message) {
    expect(fn () => new Evaluation(Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => true]))->run(Triage::class, $rows))
        ->toThrow(UnexpectedValueException::class, $message);
})->with([
    'no rows' => [[], 'There are no rows to evaluate.'],
    'no state' => [[3 => ['department' => 'billing']], 'Row 3 has no state.'],
    'a blank state' => [[['state' => '  ']], 'Row 0 has no state.'],
    'an unknown column' => [[['state' => 'x', 'team' => 'a']], 'Row 0 has a column "team", but App\Decisions\Triage asks no such question. Its questions: department, urgency, churn.'],
    'an unknown label' => [[['state' => 'x', 'department' => 'sales']], 'Row 0 labels department "sales", which isn\'t one of its labels: billing, technical, other.'],
    'a level out of range' => [[['state' => 'x', 'urgency' => 3]], 'Row 0 labels urgency "3", which isn\'t one of its labels: 0, 1, 2.'],
    'a float' => [[['state' => 'x', 'urgency' => 1.5]], 'Row 0 labels urgency "1.5"'],
    'yes for a yes/no' => [[['state' => 'x', 'churn' => 'yes']], 'Row 0 labels churn "yes", which isn\'t one of its labels: true, false.'],
]);

it('refuses a class that is not a decision class', function () {
    new Evaluation(Laya::fake())->run(Exception::class, [['state' => 'x']]);
})->throws(InvalidQuestionException::class);
