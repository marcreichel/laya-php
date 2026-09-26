<?php

declare(strict_types=1);

// Test code that uses laya without a server. This one runs offline.
//
//   php examples/05-testing-with-fake.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

// The code under test: a small ticket router that depends on Laya.
final readonly class TicketRouter
{
    public function __construct(private Laya $laya) {}

    public function queueFor(string $ticket): string
    {
        $result = $this->laya->predict($ticket, [
            'department' => Question::choice('Which department should handle this?', ['billing', 'technical', 'other']),
            'churn' => Question::yesNo('Does the user threaten to cancel or leave?'),
        ]);

        return $result->yesNo('churn')->yes()
            ? 'retention'
            : (string) $result->choice('department')->choice;
    }
}

// In a test: register the answers laya should give, run the code, assert.
$laya = Laya::fake(['department' => 'billing', 'churn' => 0.9]);

$queue = new TicketRouter($laya)->queueFor('Refund me or I cancel.');

assert($queue === 'retention');
$laya->assertPredictedCount(1);
$laya->assertPredicted(fn ($state) => $state === 'Refund me or I cancel.');

echo "Routed to: {$queue}\n";
