<?php

declare(strict_types=1);

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Attributes\Scale;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laya;

#[Scale]
enum Urgency: string
{
    #[Describe('can wait a week or more')]
    case NotUrgent = 'low';
    case Soon = 'soon';
    #[Describe('blocks the customer right now')]
    case Blocking = 'blocking';
}

#[Scale]
enum Impact: int
{
    case Minor = 10;
    case Major = 20;
}

#[Scale]
enum Nothing: string {}

final readonly class Ticket
{
    public function __construct(
        #[Ask('How urgent is this?')] public Urgency $urgency,
        #[Ask('How big is the impact?', minConfidence: 0.5)] public ?Impact $impact,
    ) {}
}

it('asks a #[Scale] enum as a score question and hydrates the case at the most likely level', function () {
    $laya = Laya::fake(['urgency' => Urgency::Blocking, 'impact' => Impact::Major]);

    expect($laya->decide('x', Ticket::class))->toEqual(new Ticket(Urgency::Blocking, Impact::Major));
    $laya->assertPredicted(fn ($state, array $questions) => $questions['urgency'] === [
        'type' => 'score',
        'instructions' => 'How urgent is this?',
        'criteria' => ['can wait a week or more', 'soon', 'blocks the customer right now'],
    ] && $questions['impact']['criteria'] === ['10', '20']);
});

it('fakes a #[Scale] enum from a decision object, and from a level index', function () {
    expect(Laya::fake(new Ticket(Urgency::Soon, null))->decide('x', Ticket::class))->toEqual(new Ticket(Urgency::Soon, null))
        ->and(Laya::fake(['urgency' => 0, 'impact' => 1])->decide('x', Ticket::class))->toEqual(new Ticket(Urgency::NotUrgent, Impact::Major));
});

it('hydrates the level by declaration position, not the backing value, and applies minConfidence', function () {
    $laya = layaRespondingWith(200, ['answers' => [
        'urgency' => ['type' => 'score', 'score' => 1.2, 'probabilities' => [0.1, 0.6, 0.3]],
        'impact' => ['type' => 'score', 'score' => 1.0, 'probabilities' => [0.2, 0.8], 'answer_confidence' => 0.4],
    ]]);

    expect($laya->decide('x', Ticket::class))->toEqual(new Ticket(Urgency::Soon, null));
});

it('decides many states into #[Scale] enums', function () {
    $laya = Laya::fake(['urgency' => Urgency::Blocking, 'impact' => Impact::Minor]);

    expect($laya->decideMany(['a' => 'x'], Ticket::class))->toEqual(['a' => new Ticket(Urgency::Blocking, Impact::Minor)]);
});

it('throws a ServerException for a level the enum has no case for', function () {
    $laya = layaRespondingWith(200, ['answers' => [
        'urgency' => ['type' => 'score', 'score' => 3.0, 'probabilities' => [0.0, 0.0, 0.0, 1.0]],
        'impact' => ['type' => 'score', 'score' => 0.0, 'probabilities' => [1.0, 0.0]],
    ]]);

    expect(fn () => $laya->decide('x', Ticket::class))
        ->toThrow(function (ServerException $e) {
            expect($e->getMessage())->toBe('The laya-serve response answers Ticket::$urgency with level 3, which isn\'t a Urgency case.')
                ->and($e->status)->toBe(200);
        });
});

it('throws a ServerException when a #[Scale] enum gets another answer type', function () {
    $laya = layaRespondingWith(200, ['answers' => [
        'urgency' => ['type' => 'choice', 'choice' => 'soon'],
        'impact' => ['type' => 'score', 'score' => 0.0],
    ]]);

    expect(fn () => $laya->decide('x', Ticket::class))
        ->toThrow(ServerException::class, 'The laya-serve response answers Ticket::$urgency with a choice, not a score.');
});

it('rejects #[Scale] enum parameters it cannot ask', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
})->with([
    '#[Levels]' => [new class(Urgency::Soon)
    {
        public function __construct(#[Ask('Urgent?'), Levels('a', 'b', 'c')] public Urgency $u) {}
    }, '::$u is a #[Scale] enum, so its cases are the levels; drop #[Levels].'],
    'no cases' => [new class(null)
    {
        public function __construct(#[Ask('Anything?')] public ?Nothing $n) {}
    }, '::$n is a #[Scale] enum without cases, so it has no levels.'],
    'threshold' => [new class(Urgency::Soon)
    {
        public function __construct(#[Ask('Urgent?', threshold: 0.4)] public Urgency $u) {}
    }, '::$u is neither a bool nor an array of enum cases, so #[Ask] can\'t take threshold.'],
    'yes' => [new class(Urgency::Soon)
    {
        public function __construct(#[Ask('Urgent?', yes: 'x')] public Urgency $u) {}
    }, '::$u is not a bool, so #[Ask] can\'t take yes or no.'],
    'minConfidence on a non-nullable' => [new class(Urgency::Soon)
    {
        public function __construct(#[Ask('Urgent?', minConfidence: 0.5)] public Urgency $u) {}
    }, 'must be nullable (?Urgency)'],
]);
