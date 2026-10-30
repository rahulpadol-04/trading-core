<?php

declare(strict_types=1);

namespace App\Enums;

enum SettlementOutcome: string
{
    case Filled = 'FILLED';
    case Rejected = 'REJECTED';
    case Waiting = 'WAITING';
    case Expired = 'EXPIRED';
    case AlreadyFinal = 'ALREADY_FINAL';
}
