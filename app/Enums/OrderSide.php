<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderSide: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';
}
