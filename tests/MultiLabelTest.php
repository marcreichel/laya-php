<?php

declare(strict_types=1);

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Of;
use MarcReichel\Laya\DecisionMapper;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;
use PHPUnit\Framework\AssertionFailedError;

enum Topic: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';

    #[Describe('login, passwords, 2FA')]
    case Account = 'account';

    case Shipping = 'shipping';
}

enum Severity: int
{
    case Minor = 0;
    case Major = 1;
}

enum Wide: int
{
    case C0 = 0;
    case C1 = 1;
    case C2 = 2;
    case C3 = 3;
    case C4 = 4;
    case C5 = 5;
    case C6 = 6;
    case C7 = 7;
    case C8 = 8;
    case C9 = 9;
    case C10 = 10;
    case C11 = 11;
    case C12 = 12;
    case C13 = 13;
    case C14 = 14;
    case C15 = 15;
    case C16 = 16;
    case C17 = 17;
    case C18 = 18;
    case C19 = 19;
    case C20 = 20;
    case C21 = 21;
    case C22 = 22;
    case C23 = 23;
    case C24 = 24;
    case C25 = 25;
    case C26 = 26;
    case C27 = 27;
    case C28 = 28;
    case C29 = 29;
    case C30 = 30;
    case C31 = 31;
    case C32 = 32;
    case C33 = 33;
    case C34 = 34;
    case C35 = 35;
    case C36 = 36;
    case C37 = 37;
    case C38 = 38;
    case C39 = 39;
    case C40 = 40;
    case C41 = 41;
    case C42 = 42;
    case C43 = 43;
    case C44 = 44;
    case C45 = 45;
    case C46 = 46;
    case C47 = 47;
    case C48 = 48;
    case C49 = 49;
    case C50 = 50;
    case C51 = 51;
    case C52 = 52;
    case C53 = 53;
    case C54 = 54;
    case C55 = 55;
    case C56 = 56;
    case C57 = 57;
    case C58 = 58;
    case C59 = 59;
    case C60 = 60;
    case C61 = 61;
    case C62 = 62;
    case C63 = 63;
}

enum Unbacked
{
    case One;
}

final readonly class Tagging
{
    /** @param list<Topic> $topics */
    public function __construct(
        #[Ask('Does this message mention {case}?', threshold: 0.3), Of(Topic::class)] public array $topics,
        #[Ask('Does the user threaten to cancel?')] public bool $churn,
    ) {}
}

final readonly class GatedTagging
{
    /**
     * @param  list<Topic>|null  $topics
     * @param  list<Severity>  $severities
     */
    public function __construct(
        #[Ask('Does this message mention {case}?', minConfidence: 0.6), Of(Topic::class)] public ?array $topics,
        #[Ask('Is it {case}?'), Of(Severity::class)] public array $severities,
    ) {}
}

/** P(yes) per Tagging question, with an answer_confidence of 0.9 unless given. */
function taggingResponse(array $noul, array $confidence = []): array
{
    $answers = [];
    foreach ($noul as $id => $p) {
        $answers[$id] = ['type' => 'noul', 'noul' => $p, 'answer_confidence' => $confidence[$id] ?? 0.9];
    }

    return ['answers' => $answers];
}

it('asks one yes/no question per case, describing it with #[Describe] or its value', function () {
    $laya = Laya::fake(['topics' => [], 'churn' => false]);
    $laya->decide('x', Tagging::class);

    $laya->assertPredicted(fn ($state, array $questions) => $questions === [
        'topics.billing' => ['type' => 'noul', 'instructions' => 'Does this message mention invoices, payments, refunds?'],
        'topics.account' => ['type' => 'noul', 'instructions' => 'Does this message mention login, passwords, 2FA?'],
        'topics.shipping' => ['type' => 'noul', 'instructions' => 'Does this message mention shipping?'],
        'churn' => ['type' => 'noul', 'instructions' => 'Does the user threaten to cancel?'],
    ]);
});

it('lists the cases whose P(yes) reaches the threshold, in declaration order', function () {
    $body = taggingResponse(['topics.shipping' => 0.9, 'topics.account' => 0.3, 'topics.billing' => 0.29, 'churn' => 0.4]);

    expect(layaRespondingWith(200, $body)->decide('x', Tagging::class))->toEqual(new Tagging([Topic::Account, Topic::Shipping], false));
});

it('gives an empty list when no case passes', function () {
    $body = taggingResponse(['topics.billing' => 0.1, 'topics.account' => 0.1, 'topics.shipping' => 0.1, 'churn' => 0.9]);

    expect(layaRespondingWith(200, $body)->decide('x', Tagging::class))->toEqual(new Tagging([], true));
});

it('is null when any case is less confident than minConfidence', function (float $confidence, ?array $topics) {
    $body = taggingResponse(
        ['topics.billing' => 0.9, 'topics.account' => 0.1, 'topics.shipping' => 0.1, 'severities.0' => 0.1, 'severities.1' => 0.5],
        ['topics.shipping' => $confidence, 'severities.0' => 0.0],
    );

    expect(layaRespondingWith(200, $body)->decide('x', GatedTagging::class))->toEqual(new GatedTagging($topics, [Severity::Major]));
})->with([
    'below' => [0.59, null],
    'at' => [0.6, [Topic::Billing]],
]);

it('decides many states', function () {
    $laya = Laya::fake(['topics' => [Topic::Billing], 'churn' => true]);

    expect($laya->decideMany(['a' => 'x', 'b' => 'y'], Tagging::class))->toEqual(['a' => new Tagging([Topic::Billing], true), 'b' => new Tagging([Topic::Billing], true)]);
});

it('fakes the per-case answers from a list of cases or values', function (array $topics) {
    $result = Laya::fake(['topics' => $topics, 'churn' => true])->predict('x', DecisionMapper::questions(Tagging::class));

    expect($result->yesNo('topics.billing')->probability)->toBe(1.0)
        ->and($result->yesNo('topics.account')->probability)->toBe(0.0)
        ->and($result->yesNo('topics.shipping')->probability)->toBe(1.0);
})->with([
    'cases' => [[Topic::Shipping, Topic::Billing]],
    'values' => [['shipping', 'billing']],
]);

it('fakes from a decision object, including unsure and int-backed lists', function () {
    $laya = Laya::fake(new GatedTagging(null, [Severity::Minor]));

    expect($laya->decide('x', GatedTagging::class))->toEqual(new GatedTagging(null, [Severity::Minor]))
        ->and(Laya::fake(['topics' => [], 'severities' => [1]])->decide('x', GatedTagging::class))->toEqual(new GatedTagging([], [Severity::Major]));
});

it('prefers an answer registered for the case question itself', function () {
    $laya = Laya::fake(['topics' => [], 'topics.account' => 0.4, 'churn' => true]);

    expect($laya->decide('x', Tagging::class)->topics)->toBe([Topic::Account]);
});

it('still fails loudly on unregistered case questions', function (array $answers) {
    expect(fn () => Laya::fake($answers)->decide('x', Tagging::class))->toThrow(LogicException::class, 'no answer for question "topics.billing"');
})->with([
    'nothing for the parameter' => [['churn' => true]],
    'a scalar for the parameter' => [['topics' => true, 'churn' => true]],
]);

it('does not answer a plain question from a list', function () {
    expect(fn () => Laya::fake(['churn' => [true]])->predict('x', ['churn' => Question::yesNo('Cancel?')]))->toThrow(LogicException::class, 'no answer for question "churn"');
});

it('asserts a decision class with #[Of] parameters was decided', function () {
    $laya = Laya::fake(new Tagging([Topic::Billing], false));
    $laya->decide('x', Tagging::class);

    expect(fn () => $laya->assertDecided(Tagging::class))->not->toThrow(AssertionFailedError::class);
});

it('rejects #[Of] parameters it cannot map', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
})->with([
    'array without #[Of]' => [new class([])
    {
        public function __construct(#[Ask('Mentions a topic?')] public array $topics) {}
    }, '::$topics is an array, so it needs #[Of(SomeEnum::class)]'],
    '#[Of] a class' => [new class([])
    {
        public function __construct(#[Ask('Mentions {case}?'), Of(stdClass::class)] public array $topics) {}
    }, '::$topics has #[Of(stdClass)], but #[Of] needs a backed enum.'],
    '#[Of] a pure enum' => [new class([])
    {
        public function __construct(#[Ask('Mentions {case}?'), Of(Unbacked::class)] public array $topics) {}
    }, 'has #[Of(Unbacked)]'],
    'yes' => [new class([])
    {
        public function __construct(#[Ask('Mentions {case}?', yes: 'x'), Of(Topic::class)] public array $topics) {}
    }, '::$topics is not a bool, so #[Ask] can\'t take yes or no.'],
    'no' => [new class([])
    {
        public function __construct(#[Ask('Mentions {case}?', no: 'x'), Of(Topic::class)] public array $topics) {}
    }, 'can\'t take yes or no'],
    'minConfidence on a non-nullable' => [new class([])
    {
        public function __construct(#[Ask('Mentions {case}?', minConfidence: 0.5), Of(Topic::class)] public array $topics) {}
    }, '::$topics sets minConfidence, so it must be nullable (?array)'],
    'no {case}' => [new class([])
    {
        public function __construct(#[Ask('Mentions a topic?'), Of(Topic::class)] public array $topics) {}
    }, '::$topics asks about each enum case, so its #[Ask] instructions need a {case} placeholder.'],
]);

it('allows up to 64 questions in all', function () {
    $dto = new class([])
    {
        public function __construct(#[Ask('Is it {case}?'), Of(Wide::class)] public array $wide) {}
    };

    expect(Laya::fake(['wide' => [Wide::C63]])->decide('x', $dto::class)->wide)->toBe([Wide::C63]);
});

it('names the #[Of] parameters that take a class past 64 questions', function (object $dto, string $message) {
    expect(fn () => Laya::fake()->decide('x', $dto::class))->toThrow(InvalidQuestionException::class, $message);
})->with([
    'with another question' => [new class(true, [])
    {
        public function __construct(#[Ask('Cancel?')] public bool $churn, #[Ask('Is it {case}?'), Of(Wide::class)] public array $wide) {}
    }, 'asks 65 questions, but laya-serve answers at most 64 per request. $wide asks one question per enum case.'],
    'two #[Of] parameters' => [new class([], [])
    {
        public function __construct(#[Ask('Is it {case}?'), Of(Wide::class)] public array $a, #[Ask('Is it {case}?'), Of(Severity::class)] public array $b) {}
    }, 'asks 66 questions, but laya-serve answers at most 64 per request. $a, $b ask one question per enum case.'],
]);

it('throws a ServerException when a case answer does not fit', function (array $answers, string $message, string $previous) {
    expect(fn () => layaRespondingWith(200, ['answers' => $answers])->decide('x', Tagging::class))
        ->toThrow(function (ServerException $e) use ($message, $previous) {
            expect($e->getMessage())->toBe($message)
                ->and($e->status)->toBe(200)
                ->and($e->getPrevious())->toBeInstanceOf($previous);
        });
})->with([
    'missing case answer' => [
        taggingResponse(['topics.billing' => 0.9, 'topics.shipping' => 0.1, 'churn' => 0.1])['answers'],
        'The laya-serve response has no answer for Tagging::$topics (question "topics.account").',
        OutOfBoundsException::class,
    ],
    'choice for a case' => [
        ['topics.billing' => ['type' => 'choice', 'choice' => 'yes']] + taggingResponse(['topics.account' => 0.1, 'topics.shipping' => 0.1, 'churn' => 0.1])['answers'],
        'The laya-serve response answers Tagging::$topics (question "topics.billing") with a choice, not a yes/no.',
        UnexpectedValueException::class,
    ],
]);
