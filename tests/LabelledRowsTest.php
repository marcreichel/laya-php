<?php

declare(strict_types=1);

use MarcReichel\Laya\LabelledRows;

it('reads CSV rows by their row number, with multi-line states and a byte order mark', function () {
    $path = labelledFile('CSV', "\u{FEFF}state,department,churn\n\"Billed twice,\nrefund it\",billing,true\n\nAll good,,false\n");

    expect(LabelledRows::read($path))->toBe([
        2 => ['state' => "Billed twice,\nrefund it", 'department' => 'billing', 'churn' => 'true'],
        4 => ['state' => 'All good', 'department' => '', 'churn' => 'false'],
    ]);
});

it('reads JSON Lines rows by their line number', function (string $extension) {
    $path = labelledFile($extension, "{\"state\": \"Billed twice\", \"urgency\": 2, \"churn\": true}\n\n{\"state\": \"All good\", \"churn\": null}\n");

    expect(LabelledRows::read($path))->toBe([
        1 => ['state' => 'Billed twice', 'urgency' => 2, 'churn' => true],
        3 => ['state' => 'All good', 'churn' => null],
    ]);
})->with(['jsonl', 'ndjson']);

it('refuses what it cannot read', function (string $extension, string $contents, string $message) {
    expect(fn () => LabelledRows::read(labelledFile($extension, $contents)))->toThrow(UnexpectedValueException::class, $message);
})->with([
    'another format' => ['txt', 'state', 'is neither CSV (.csv) nor JSON Lines (.jsonl, .ndjson).'],
    'an empty CSV' => ['csv', '', 'The CSV file is empty; it needs a header row with a "state" column.'],
    'a short CSV row' => ['csv', "state,department\nx,billing\ny\n", 'Row 3 has 1 fields, but the header has 2.'],
    'a repeated CSV column' => ['csv', "state,churn,churn\nx,true,false\n", 'The CSV header repeats churn.'],
    'a blank CSV header' => ['csv', "\nx,billing\n", 'Row 2 has 2 fields, but the header has 1.'],
    'a JSON list' => ['jsonl', "{\"state\": \"x\"}\n[\"y\"]\n", 'Row 2 is not a JSON object.'],
    'invalid JSON' => ['jsonl', "nope\n", 'Row 1 is not a JSON object.'],
]);

it('refuses a file it cannot open', function () {
    LabelledRows::read('/nonexistent/triage.csv');
})->throws(UnexpectedValueException::class, 'Cannot read "/nonexistent/triage.csv".');
