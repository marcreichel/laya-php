<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

enum QuestionType: string
{
    case Choice = 'choice';
    case Score = 'score';
    case YesNo = 'noul';
}
