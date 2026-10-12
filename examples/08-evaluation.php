<?php

declare(strict_types=1);

// Measure a decision class against labelled examples, without Laravel. This one runs offline on a fake;
// pass a real Laya to measure your server.
//
//   php examples/08-evaluation.php
//
// From the command line, with the examples in a CSV or JSON Lines file:
//
//   vendor/bin/laya-eval "App\Decisions\ReviewCheck" reviews.csv --precision=0.9

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Evaluation;
use MarcReichel\Laya\Laya;

enum Sentiment: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Mixed = 'mixed';
}

final readonly class ReviewCheck
{
    public function __construct(
        #[Ask('What is the overall sentiment of the review?')]
        public Sentiment $sentiment,

        #[Ask('Does the review insult anyone?')]
        public bool $abusive,
    ) {}
}

// Answers as a server might give them: unsure about the mixed review, half sure about the rude one.
$laya = Laya::fake(['sentiment' => 'positive', 'abusive' => 0.1])
    ->when(fn (mixed $state) => is_string($state) && str_contains($state, 'garbage'), ['sentiment' => 'negative', 'abusive' => 0.45])
    ->when(fn (mixed $state) => is_string($state) && str_contains($state, 'but'), ['sentiment' => null, 'abusive' => 0.2]);

// Or LabelledRows::read('reviews.csv'): a state column, and one column per labelled parameter.
$rows = [
    ['state' => 'Great product, fast shipping.', 'sentiment' => 'positive', 'abusive' => 'false'],
    ['state' => 'Total garbage, you idiots.', 'sentiment' => 'negative', 'abusive' => 'true'],
    ['state' => 'Works, but the manual is useless.', 'sentiment' => 'mixed', 'abusive' => 'false'],
    ['state' => 'Love it!', 'sentiment' => 'positive'],
];

$report = new Evaluation($laya)->run(ReviewCheck::class, $rows, precision: 0.9);

echo $report->render(), "\n";
