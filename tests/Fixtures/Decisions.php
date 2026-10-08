<?php

declare(strict_types=1);

// Decision classes under App\, as an app would declare them, for laya:try's short class names.

namespace App\Decisions;

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Attributes\Of;

enum Department: string
{
    #[Describe('invoices, refunds')]
    case Billing = 'billing';
    case Technical = 'technical';
    case Other = 'other';
}

final readonly class Triage
{
    public function __construct(
        #[Ask('Which department should handle this?')] public Department $department,
        #[Ask('How urgent is this?'), Levels('not urgent', 'soon', 'blocking')] public int $urgency,
        #[Ask('Does the user threaten to cancel?', minConfidence: 0.85)] public ?bool $churn,
    ) {}
}

enum Tag: string
{
    case Info = '<info>';
}

final readonly class Tagged
{
    public function __construct(#[Ask('Which <comment>tag</comment>?')] public Tag $tag) {}
}

final readonly class Broken
{
    public function __construct(#[Ask('Name?')] public string $name) {}
}

enum Code: int
{
    case Zero = 0;
    case One = 1;
}

final readonly class Coded
{
    public function __construct(#[Ask('Which code?')] public Code $code) {}
}

enum Area: string
{
    #[Describe('invoices, refunds')]
    case Billing = 'billing';
    case Docs = 'docs.api';
}

final readonly class Areas
{
    /** @param list<Area>|null $areas */
    public function __construct(
        #[Ask('Is this about {case}?', minConfidence: 0.5), Of(Area::class)] public ?array $areas,
        #[Ask('Does the user threaten to cancel?')] public bool $churn,
    ) {}
}
