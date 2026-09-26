<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/**
 * The checkpoints laya-serve can be pinned to. Leave it out to let laya's router pick one.
 */
enum Model: string
{
    /** ModernBERT-large, English, 512 tokens. */
    case English = 'english';

    /** mmBERT-base, 100+ languages, 1,024 tokens. */
    case Multilingual = 'multilingual';

    /** ModernBERT-large, fine-tuned on the typed-decisions workflows. */
    case TypedDecisions = 'typed-decisions';
}
