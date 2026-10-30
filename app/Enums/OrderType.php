<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderType: string
{
    case Limit = 'LIMIT';
    case Market = 'MARKET';
}
