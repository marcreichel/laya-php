<?php

declare(strict_types=1);

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Levels;

enum TypeSafeDepartment: string
{
    case Billing = 'billing';
    case Technical = 'technical';
    case Other = 'other';
}

final readonly class TypeSafeTriage
{
    public function __construct(
        #[Ask('Which department should handle this?', minConfidence: 0.7)] public ?TypeSafeDepartment $department,
        #[Ask('How urgent is this?', minConfidence: 0.7), Levels('not urgent', 'soon', 'blocking')] public ?int $urgency,
        #[Ask('Does the user threaten to cancel?', minConfidence: 0.8)] public ?bool $churn,
    ) {}
}

it('reads a response in the shape of TypeSafe\'s OpenAPI spec', function () {
    $result = layaRespondingWith(200, TYPESAFE_RESPONSE)->predict('Billed twice, refund or I cancel.', questions());

    expect($result->routedModel)->toBeNull()
        ->and($result->inputTokens)->toBe(42)
        ->and($result->choice('department')->choice)->toBe('billing')
        ->and($result->choice('department')->answerConfidence)->toBe(0.934)
        ->and($result->score('urgency')->label())->toBe('blocking')
        ->and($result->score('urgency')->answerConfidence)->toBe(0.71)
        ->and($result->yesNo('churn')->yes())->toBeTrue()
        ->and($result->yesNo('churn')->confidence)->toBe(0.83)
        ->and($result->yesNo('churn')->answerConfidence)->toBe(0.83);
});

it('keeps minConfidence working against servers without answer_confidence', function () {
    expect(layaRespondingWith(200, TYPESAFE_RESPONSE)->decide('x', TypeSafeTriage::class))->toEqual(new TypeSafeTriage(TypeSafeDepartment::Billing, 2, true));

    $unsure = TYPESAFE_RESPONSE;
    $unsure['answers']['department']['confidence'] = 0.69;
    $unsure['answers']['churn']['noul'] = 0.25;

    expect(layaRespondingWith(200, $unsure)->decide('x', TypeSafeTriage::class))->toEqual(new TypeSafeTriage(null, 2, null));
});
