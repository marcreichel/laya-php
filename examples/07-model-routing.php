<?php

declare(strict_types=1);

// Route each prompt to the cheapest Claude model that can handle it, before paying for a single token.
// Ask what kind of task the prompt is, not which model fits: laya knows tasks, not models.
// The backing values are short names, since laya reads them as labels; id() maps them to model IDs.
//
//   php examples/07-model-routing.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Laya;

enum ClaudeModel: string
{
    #[Describe('a simple translation, lookup or rewrite')]
    case Haiku = 'haiku';

    #[Describe('writing a common query, script, email or summary')]
    case Sonnet = 'sonnet';

    #[Describe('finding and fixing a subtle bug or analysing a complex system')]
    case Opus = 'opus';

    #[Describe('inventing new theories, hypotheses or proofs')]
    case Fable = 'fable';

    public function id(): string
    {
        return match ($this) {
            self::Haiku => 'claude-haiku-5-5',
            self::Sonnet => 'claude-sonnet-5-5',
            self::Opus => 'claude-opus-5-5',
            self::Fable => 'claude-fable-5-1',
        };
    }
}

final readonly class Route
{
    public function __construct(
        #[Ask('What kind of task is this?', minConfidence: 0.6)]
        public ?ClaudeModel $model, // null when laya is unsure
    ) {}
}

$laya = new Laya(getenv('LAYA_URL') ?: 'http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

$prompts = [
    'Translate "Where is the train station?" into French.',
    'Write a SQL query that lists the ten customers with the highest revenue last month.',
    'Find the race condition in this Go worker pool and explain how to fix it without a global lock.',
    'Come up with a new hypothesis for why dark matter has never been detected, and design experiments to test it.',
    'Help me with my thing.',
];

foreach ($laya->decideMany($prompts, Route::class) as $i => $route) {
    $model = $route->model ?? ClaudeModel::Sonnet;

    printf("%-18s %s%s\n", $model->id(), $prompts[$i], $route->model === null ? '  (unsure, default)' : '');
}
