<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/** How laya-serve's abstention gate decided an answer; see the minConfidence of {@see Laya::predict()}. */
enum Abstention: string
{
    /** The answer's confidence cleared the threshold. */
    case Passed = 'passed';

    /** The answer's confidence fell below the threshold. laya keeps the answer, and marks it low_confidence. */
    case Abstained = 'abstained';

    /** The answer carried no usable confidence, so the gate couldn't decide. */
    case Unevaluated = 'unevaluated';
}
