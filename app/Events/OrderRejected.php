<?php

declare(strict_types=1);

namespace App\Events;

final class OrderRejected extends OrderEvent
{
    public function auditAction(): string
    {
        return 'order.rejected';
    }
}
