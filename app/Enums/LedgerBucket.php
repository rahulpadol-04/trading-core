<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerBucket: string
{
    case Available = 'AVAILABLE';
    case Reserved = 'RESERVED';
}
