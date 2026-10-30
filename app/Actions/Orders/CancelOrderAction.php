<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
use App\Exceptions\Domain\OrderNotCancellable;
use App\Models\Order;
use App\Support\OrderSnapshot;
use Illuminate\Support\Facades\DB;

final class CancelOrderAction
{
    public const string REASON_USER = 'USER_REQUESTED';

    public const string REASON_EXPIRED = 'EXPIRED';

    public function __construct(private readonly ReleaseReservation $release) {}

    /**
     * Cancel and release exactly once. Locks the order row first, so a concurrent fill
     * either finishes before this reads the status or sees CANCELLED afterwards.
     *
     * @throws OrderNotCancellable when the order is FILLED, REJECTED or FAILED
     */
    public function execute(Order $order, string $reason, ?int $actorId, ?string $ip): Order
    {
        return DB::transaction(function () use ($order, $reason, $actorId, $ip): Order {
            $order = $order->lockFresh();

            if ($order->status === OrderStatus::Cancelled) {
                return $order;
            }

            if ($order->status !== OrderStatus::Pending) {
                throw new OrderNotCancellable($order->status);
            }

            $before = OrderSnapshot::of($order);

            ($this->release)($order);

            $order->status = OrderStatus::Cancelled;
            $order->cancelled_at = now()->toImmutable();
            $order->failure_reason = $reason === self::REASON_USER ? null : $reason;
            $order->save();

            OrderCancelled::dispatch($order->id, $before, OrderSnapshot::of($order), $actorId, $ip, ['reason' => $reason]);

            return $order;
        }, attempts: 3);
    }
}
