<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

final class OrderSnapshot
{
    /**
     * @return array<string, string|null>
     */
    public static function of(Order $order): array
    {
        return [
            'status' => $order->status->value,
            'quantity' => (string) $order->quantity,
            'limit_price' => $order->limit_price?->normalized(),
            'reserved_amount' => (string) $order->reserved_amount,
            'failure_reason' => $order->failure_reason,
        ];
    }
}
