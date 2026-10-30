<?php

declare(strict_types=1);

namespace App\Events;

final class OrderFilled extends OrderEvent
{
    public function auditAction(): string
    {
        return 'order.filled';
    }
}
