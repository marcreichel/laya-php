<?php

declare(strict_types=1);

// Ask ad-hoc questions and read the typed answers.
//
//   php examples/01-predict.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

$laya = new Laya(getenv('LAYA_URL') ?: 'http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

$result = $laya->predict('Hi, we were billed twice for March. Please refund the duplicate today or we will cancel our plan.', [
    'department' => Question::choice('Which department should handle this?', [
        'billing' => 'invoices, payments, refunds',
        'technical' => 'bugs, outages, system errors',
        'other' => 'everything else',
    ]),
    'urgency' => Question::score('How urgent is this?', ['not urgent', 'soon', 'blocking']),
    'churn_risk' => Question::yesNo('Does the user threaten to cancel or leave?'),
]);

$department = $result->choice('department');
$urgency = $result->score('urgency');
$churn = $result->yesNo('churn_risk');

printf("department: %s (confidence %.2f)\n", $department->choice, $department->answerConfidence);
printf("urgency:    %s (level %d, expected %.2f)\n", $urgency->label(), $urgency->level(), $urgency->score);
printf("churn risk: %s (P(yes) = %.2f)\n", $churn->yes() ? 'yes' : 'no', $churn->probability);
printf("checkpoint: %s\n", $result->routedModel);
