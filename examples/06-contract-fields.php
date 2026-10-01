<?php

declare(strict_types=1);

// Find which contract fields you need to answer a question about the contract.
//
//   php examples/06-contract-fields.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

$fields = [
    'parties' => 'who the customer and the provider are',
    'start_date' => 'when the contract takes effect',
    'term' => 'how long the contract runs',
    'notice_period' => 'how far in advance either side must give notice to end the contract',
    'auto_renewal' => 'whether and for how long the contract renews automatically',
    'fees' => 'what the customer pays for the service',
    'payment_terms' => 'when invoices are due and how they are paid',
    'price_adjustment' => 'whether and how the provider may raise prices',
    'sla' => 'guaranteed availability, response times and service credits',
    'liability_cap' => 'the maximum amount either side is liable for',
    'indemnification' => 'who covers third-party claims, e.g. for IP infringement',
    'confidentiality' => 'how confidential information must be protected',
    'data_protection' => 'personal data processing, GDPR, data processing agreement',
    'intellectual_property' => 'who owns the software, work results and customer data',
    'warranty' => 'what the provider promises about the quality of the service',
    'termination_for_cause' => 'ending the contract early because of a breach',
    'governing_law' => 'which country\'s law applies',
    'jurisdiction' => 'which court handles disputes',
    'subcontracting' => 'whether the provider may use subcontractors',
    'audit_rights' => 'whether the customer may audit the provider',
];

// One yes/no question per field catches questions that touch several fields. Its
// probabilities run low, so the threshold is 0.2. One choice question over all fields
// is sharper when a single field is meant; trust its pick only when it is confident.
$questions = array_map(
    fn (string $description) => Question::yesNo("Do you need to know the contract's clause on {$description} to answer this question?"),
    $fields,
) + ['main_clause' => Question::choice('Which contract clause do you need to answer this question?', $fields)];

$laya = new Laya(getenv('LAYA_URL') ?: 'http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

$inputs = [
    'Who do we sue in if things go wrong, and under which law?',
    'Can we cancel before the end of the year, and will it renew if we forget?',
    'Darf der Anbieter die Preise nächstes Jahr einfach erhöhen?',
    'What is the weather in Berlin tomorrow?',
];

foreach ($inputs as $input) {
    $result = $laya->predict($input, $questions);

    $relevant = [];
    foreach (array_keys($fields) as $field) {
        $answer = $result->yesNo($field);
        if ($answer->yes(threshold: 0.2)) {
            $relevant[$field] = $answer->probability;
        }
    }

    $main = $result->choice('main_clause');
    if ($main->answerConfidence >= 0.7) {
        $relevant[$main->choice] = max($relevant[$main->choice] ?? 0.0, $main->answerConfidence);
    }
    arsort($relevant);

    echo $input, "\n";
    foreach ($relevant as $field => $probability) {
        printf("  %-22s %.2f\n", $field, $probability);
    }
    if ($relevant === []) {
        echo "  (no contract field)\n";
    }
}
