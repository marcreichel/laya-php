<?php

declare(strict_types=1);

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Laya;

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

it('explains what is wrong with an unsupported decision class', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
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

it('maps a numeric choice onto a string-backed enum', function () {
    $coded = layaRespondingWith(200, ['answers' => ['code' => ['type' => 'choice', 'choice' => 2]]])->decide('x', Coded::class);

    expect($coded->code)->toBe(Code::Two);
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
    }, 'is not a bool, so #[Ask] can\'t take yes, no or threshold'],
    'no on an int' => [new class(1)
    {
        public function __construct(#[Ask('Urgent?', no: 'x'), Levels('a')] public int $u) {}
    }, 'is not a bool'],
    'threshold on an enum' => [new class(Department::Billing)
    {
        public function __construct(#[Ask('Dept?', threshold: 0.4)] public Department $d) {}
    }, 'is not a bool'],
]);
