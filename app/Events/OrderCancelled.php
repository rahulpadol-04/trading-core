<?php

declare(strict_types=1);

namespace App\Events;

final class OrderCancelled extends OrderEvent
{
    public function auditAction(): string
    {
        return 'order.cancelled';
    }
}
