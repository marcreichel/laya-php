<?php

declare(strict_types=1);

// The same questions in any language: laya's router sends non-English text to the multilingual checkpoint.
// Structured state (arrays, JsonSerializable) works as well as plain text.
//
//   php examples/03-multilingual.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;

$laya = new Laya(getenv('LAYA_URL') ?: 'http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

$questions = [
    'department' => Question::choice('Which department should handle this?', [
        'billing' => 'invoices, payments, refunds',
        'technical' => 'bugs, outages, system errors',
        'other' => 'everything else',
    ]),
];

foreach ([
    'Mein Konto wurde zweimal belastet, bitte erstatten Sie den doppelten Betrag.',
    'La aplicación se cierra cada vez que abro la configuración.',
    'मुझसे मार्च में दो बार शुल्क लिया गया, कृपया डुप्लिकेट राशि वापस करें।',
] as $text) {
    $result = $laya->predict($text, $questions);
    printf("%-12s %-9s %s\n", $result->routedModel, $result->choice('department')->choice, $text);
}

// An email as a JSON document, pinned to the multilingual checkpoint.
$email = [
    'from' => 'kunde@example.de',
    'subject' => 'Rechnung März',
    'body' => 'Guten Tag, auf der Rechnung für März ist die Lizenz doppelt aufgeführt.',
];
$result = $laya->predict($email, $questions, model: Model::Multilingual);
printf("%-12s %-9s (email)\n", $result->routedModel, $result->choice('department')->choice);
