<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderFailed;
use App\Models\Order;
use App\Support\OrderSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FailOrderAction
{
    public function __construct(private readonly ReleaseReservation $release) {}

    public function execute(int $orderId, ?Throwable $cause): void
    {
        DB::transaction(function () use ($orderId, $cause): void {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || ! $order->status->holdsReservation()) {
                return;
            }

            $before = OrderSnapshot::of($order);

            ($this->release)($order);

            $order->status = OrderStatus::Failed;
            $order->failure_reason = 'PROCESSING_FAILED: '.($cause === null ? 'unknown' : class_basename($cause));
            $order->save();

            OrderFailed::dispatch($order->id, $before, OrderSnapshot::of($order));
        }, attempts: 3);

        Log::error('Order processing failed permanently', [
            'order_id' => $orderId,
            'exception' => $cause?->getMessage(),
        ]);
    }
}
