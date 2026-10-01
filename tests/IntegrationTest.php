<?php

declare(strict_types=1);

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

// Runs against a real laya-serve: `docker compose up -d --wait` then `LAYA_URL=http://localhost:8000 composer test:integration`.
beforeEach(function () {
    if (! getenv('LAYA_URL')) {
        $this->markTestSkipped('Set LAYA_URL to run against a real laya-serve.');
    }
    $this->laya = new Laya(getenv('LAYA_URL'), apiKey: getenv('LAYA_API_KEY') ?: null);
});

it('answers the README example', function () {
    $result = $this->laya->predict('Hi, we were billed twice for March. Please refund the duplicate today or we will cancel our plan.', [
        'department' => Question::choice('Which department should handle this?', [
            'billing' => 'invoices, payments, refunds',
            'technical' => 'bugs, outages, system errors',
            'other' => 'everything else',
        ]),
        'churn_risk' => Question::yesNo('Does the user threaten to cancel or leave?'),
    ]);

    expect($result->choice('department')->choice)->toBe('billing')
        ->and($result->yesNo('churn_risk')->yes())->toBeTrue();
})->group('integration');

it('reports a state cut off at its token budget', function () {
    $question = ['spam' => Question::yesNo('Is this spam?')];

    expect($this->laya->predict(str_repeat('Lorem ipsum dolor sit amet. ', 400), $question)->truncated)->toBeTrue()
        ->and($this->laya->predict('Hello there.', $question)->truncated)->toBeFalse();
})->group('integration');

it('reports health', function () {
    expect($this->laya->health()->ok)->toBeTrue();
})->group('integration');
