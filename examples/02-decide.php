<?php

declare(strict_types=1);

// Describe a decision as a class and get an instance back.
//
//   php examples/02-decide.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Laya;

enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';

    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';

    #[Describe('everything else')]
    case Other = 'other';
}

final readonly class Triage
{
    public function __construct(
        #[Ask('Which department should handle this?')]
        public Department $department,

        #[Ask('How urgent is this?'), Levels('not urgent', 'soon', 'blocking')]
        public int $urgency,

        #[Ask('Does the user threaten to cancel or leave?')]
        public bool $churn,
    ) {}
}

$laya = new Laya(getenv('LAYA_URL') ?: 'http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

$tickets = [
    'Hi, we were billed twice for March. Please refund the duplicate today or we will cancel our plan.',
    'The app crashes every time I open the settings page since the last update.',
    'Do you have a dark mode? Just curious, no rush.',
];

foreach ($tickets as $ticket) {
    $triage = $laya->decide($ticket, Triage::class);

    printf("%-10s urgency %d  churn %-3s  %s\n", $triage->department->value, $triage->urgency, $triage->churn ? 'yes' : 'no', $ticket);
}
