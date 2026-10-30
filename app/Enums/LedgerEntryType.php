<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case Deposit = 'DEPOSIT';
    case Reserve = 'RESERVE';
    case Release = 'RELEASE';
    case Trade = 'TRADE';
}
