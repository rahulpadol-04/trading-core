<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchDecision: string
{
    case Fill = 'FILL';
    case Wait = 'WAIT';
    case Reject = 'REJECT';
}
