<?php

declare(strict_types=1);

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\DecisionMapper;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use PHPUnit\Framework\AssertionFailedError;

enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';
    case Technical = 'technical';
}

enum Priority: int
{
    case Low = 0;
    case High = 1;
}

final readonly class Triage
{
    public function __construct(
        #[Ask('Which department should handle this?')] public Department $department,
        #[Ask('How urgent is this?'), Levels('not urgent', 'soon', 'blocking')] public int $urgency,
        #[Ask('Does the user threaten to cancel?')] public bool $churn,
        #[Ask('Priority?')] public Priority $priority,
    ) {}
}

it('asks the questions a decision class declares and hydrates it', function () {
    $laya = Laya::fake(['department' => Department::Billing, 'urgency' => 2, 'churn' => true, 'priority' => Priority::High]);

    $triage = $laya->decide('Billed twice, refund or I cancel.', Triage::class);

    expect($triage)->toEqual(new Triage(Department::Billing, 2, true, Priority::High));
    $laya->assertPredicted(fn ($state, array $questions) => $questions['department'] === [
        'type' => 'choice',
        'instructions' => 'Which department should handle this?',
        'criteria' => ['billing' => 'invoices, payments, refunds', 'technical' => null],
    ] && $questions['priority']['criteria'] === ['0' => null, '1' => null]
      && $questions['urgency']['criteria'] === ['not urgent', 'soon', 'blocking']
      && $questions['churn']['type'] === 'noul');
});

it('explains what is wrong with an unsupported decision class, on every call', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message)
        ->and(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
})->with([
    'missing #[Ask]' => [new class(true)
    {
        public function __construct(public bool $churn) {}
    }, 'needs an #[Ask(...)]'],
    'int without #[Levels]' => [new class(1)
    {
        public function __construct(#[Ask('Urgent?')] public int $urgency) {}
    }, 'needs #[Levels(...)]'],
    'free string' => [new class('x')
    {
        public function __construct(#[Ask('Name?')] public string $name) {}
    }, 'has type string'],
    'no constructor' => [new class {}, 'needs a constructor'],
    'union type' => [new class(true)
    {
        public function __construct(#[Ask('Cancel?')] public bool|int $churn) {}
    }, 'needs a single declared type'],
]);

enum Code: string
{
    case One = '1';
    case Two = '2';
}

final readonly class Coded
{
    public function __construct(#[Ask('Which code?')] public Code $code) {}
}

it('reflects a decision class once per process', function () {
    expect(DecisionMapper::questions(Triage::class))->toBe(DecisionMapper::questions(Triage::class));
});

it('maps a numeric choice onto a string-backed enum', function () {
    $coded = layaRespondingWith(200, ['answers' => ['code' => ['type' => 'choice', 'choice' => 2]]])->decide('x', Coded::class);

    expect($coded->code)->toBe(Code::Two);
});

it('forwards the model and token budgets', function () {
    $sent = [];
    layaRespondingWith(200, ['answers' => ['code' => ['type' => 'choice', 'choice' => 2]]], $sent)
        ->decide('x', Coded::class, Model::English, maxLen: 2048, headMaxLen: 256);

    expect(json_decode((string) $sent[0]->getBody(), true))->toMatchArray(['model' => 'english', 'max_len' => 2048, 'head_max_len' => 256]);
});

it('keeps #[Levels] a list, even from named arguments', function () {
    expect(new Levels(...['low' => 'low', 'high' => 'high'])->levels)->toBe(['low', 'high']);
});

final readonly class Gated
{
    public function __construct(
        #[Ask('Which department?', minConfidence: 0.7)] public ?Department $department,
        #[Ask('How urgent?', minConfidence: 0.7), Levels('low', 'high')] public ?int $urgency,
        #[Ask('Cancel?', yes: 'says they will leave', no: 'happy customer', threshold: 0.3, minConfidence: 0.2)] public ?bool $churn,
    ) {}
}

it('returns null where laya is less confident than minConfidence', function () {
    $body = ['answers' => [
        'department' => ['type' => 'choice', 'choice' => 'billing', 'answer_confidence' => 0.69],
        'urgency' => ['type' => 'score', 'score' => 1.0, 'probabilities' => [0.2, 0.8], 'answer_confidence' => 0.7],
        'churn' => ['type' => 'noul', 'noul' => 0.3, 'answer_confidence' => 0.2],
    ]];

    expect(layaRespondingWith(200, $body)->decide('x', Gated::class))->toEqual(new Gated(null, 1, true));
});

it('sends yes/no descriptions and applies the yes threshold', function () {
    $laya = Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => 0.29]);

    expect($laya->decide('x', Gated::class)->churn)->toBeFalse();
    $laya->assertPredicted(fn ($state, array $questions) => $questions['churn']['criteria'] === ['true' => 'says they will leave', 'false' => 'happy customer']);
});

it('rejects #[Ask] options that cannot apply', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
})->with([
    'minConfidence on a non-nullable' => [new class(true)
    {
        public function __construct(#[Ask('Cancel?', minConfidence: 0.5)] public bool $churn) {}
    }, 'must be nullable (?bool)'],
    'yes on an enum' => [new class(Department::Billing)
    {
        public function __construct(#[Ask('Dept?', yes: 'x')] public Department $d) {}
    }, 'is not a bool, so #[Ask] can\'t take yes or no.'],
    'no on an int' => [new class(1)
    {
        public function __construct(#[Ask('Urgent?', no: 'x'), Levels('a')] public int $u) {}
    }, 'is not a bool'],
    'threshold on an enum' => [new class(Department::Billing)
    {
        public function __construct(#[Ask('Dept?', threshold: 0.4)] public Department $d) {}
    }, '::$d is neither a bool nor an array of enum cases, so #[Ask] can\'t take threshold.'],
]);

it('fakes answers from a decision object, including unsure ones', function () {
    $laya = Laya::fake(new Gated(null, null, null));

    expect($laya->decide('x', Gated::class))->toEqual(new Gated(null, null, null));
});

it('asserts a decision class was decided', function () {
    $laya = Laya::fake(new Triage(Department::Billing, 2, true, Priority::High));
    $laya->decide('Refund me.', Triage::class, Model::English);

    expect(fn () => $laya->assertDecided(Triage::class))->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertDecided(Triage::class, fn ($state, $model) => $state === 'Refund me.' && $model === 'english'))->not->toThrow(AssertionFailedError::class)
        ->and(fn () => $laya->assertDecided(Triage::class, fn ($state) => $state === 'other'))->toThrow(AssertionFailedError::class, 'Expected Triage to be decided')
        ->and(fn () => $laya->assertDecided(Gated::class))->toThrow(AssertionFailedError::class);
});

/** Triage's answers, with $changes applied; a null change drops the answer. */
function triageResponse(array $changes = []): array
{
    $answers = array_filter($changes + [
        'department' => ['type' => 'choice', 'choice' => 'billing'],
        'urgency' => ['type' => 'score', 'score' => 2.0, 'probabilities' => [0.1, 0.1, 0.8]],
        'churn' => ['type' => 'noul', 'noul' => 0.9],
        'priority' => ['type' => 'choice', 'choice' => 1],
    ]);

    return ['answers' => $answers];
}

it('throws a ServerException when the response does not fit the decision class', function (array $changes, string $message, string $previous) {
    expect(fn () => layaRespondingWith(200, triageResponse($changes))->decide('x', Triage::class))
        ->toThrow(function (ServerException $e) use ($message, $previous) {
            expect($e->getMessage())->toBe($message)
                ->and($e->status)->toBe(200)
                ->and($e->getPrevious())->toBeInstanceOf($previous);
        });
})->with([
    'missing answer' => [['department' => null], 'The laya-serve response has no answer for Triage::$department.', OutOfBoundsException::class],
    'score for a choice' => [['department' => ['type' => 'score', 'score' => 1.0]], 'The laya-serve response answers Triage::$department with a score, not a choice.', UnexpectedValueException::class],
    'yes/no for a score' => [['urgency' => ['type' => 'noul', 'noul' => 0.5]], 'The laya-serve response answers Triage::$urgency with a yes/no, not a score.', UnexpectedValueException::class],
    'choice for a yes/no' => [['churn' => ['type' => 'choice', 'choice' => 'billing']], 'The laya-serve response answers Triage::$churn with a choice, not a yes/no.', UnexpectedValueException::class],
    'unknown string label' => [['department' => ['type' => 'choice', 'choice' => 'refunds']], 'The laya-serve response answers Triage::$department with "refunds", which isn\'t a Department case.', ValueError::class],
    'unknown int label' => [['priority' => ['type' => 'choice', 'choice' => 5]], 'The laya-serve response answers Triage::$priority with "5", which isn\'t a Priority case.', ValueError::class],
]);

it('checks the answer type before minConfidence', function () {
    $body = ['answers' => [
        'department' => ['type' => 'noul', 'noul' => 0.9, 'answer_confidence' => 0.1],
        'urgency' => ['type' => 'score', 'score' => 1.0, 'probabilities' => [0.2, 0.8]],
        'churn' => ['type' => 'noul', 'noul' => 0.3],
    ]];

    expect(fn () => layaRespondingWith(200, $body)->decide('x', Gated::class))
        ->toThrow(ServerException::class, 'The laya-serve response answers Gated::$department with a yes/no, not a choice.');
});

it('throws a ServerException from decideMany when a response does not fit the decision class', function () {
    // layaBatching() answers department, urgency and churn, but not Triage's priority.
    expect(fn () => layaBatching()->decideMany(['a' => 'x'], Triage::class))
        ->toThrow(ServerException::class, 'The laya-serve response has no answer for Triage::$priority.');
});
