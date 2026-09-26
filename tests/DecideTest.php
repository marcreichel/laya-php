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
]);
