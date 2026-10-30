<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Filled = 'FILLED';
    case Cancelled = 'CANCELLED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return ! $this->holdsReservation();
    }

    /**
     * States in which the order still holds cash or quantity in reserve.
     */
    public function holdsReservation(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
