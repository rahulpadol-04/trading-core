<?php

declare(strict_types=1);

namespace App\Events;

final class OrderPlaced extends OrderEvent
{
    public function auditAction(): string
    {
        return 'order.placed';
    }
}
