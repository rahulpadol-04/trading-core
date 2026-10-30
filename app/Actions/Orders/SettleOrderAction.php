<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\MatchDecision;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SettlementOutcome;
use App\Events\OrderFilled;
use App\Events\OrderRejected;
use App\Models\Execution;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\MatchingRules;
use App\Services\PriceStore;
use App\Support\Decimal;
use App\Support\OrderSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Settles one order in one transaction. Safe to run twice, late, or concurrently with a
 * cancel: everything is re-checked under the order's row lock.
 */
final class SettleOrderAction
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly PriceStore $prices,
        private readonly MatchingRules $rules,
        private readonly ReleaseReservation $release,
        private readonly CancelOrderAction $cancel,
    ) {}

    public function execute(int $orderId): SettlementOutcome
    {
        return DB::transaction(function () use ($orderId): SettlementOutcome {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || $order->status !== OrderStatus::Pending) {
                return SettlementOutcome::AlreadyFinal;
            }

            if ($order->expires_at?->isPast()) {
                $this->cancel->execute($order, CancelOrderAction::REASON_EXPIRED, null, null);

                return SettlementOutcome::Expired;
            }

            $price = $this->prices->fresh($order->asset_id, config('trading.price_max_age_seconds'));
            $decision = $this->rules->decide($order->side, $order->type, $order->limit_price, $price);

            if ($decision === MatchDecision::Wait) {
                return SettlementOutcome::Waiting;
            }

            if ($decision === MatchDecision::Reject || $price === null) {
                return $this->reject($order, 'NO_RECENT_PRICE');
            }

            return $this->fill($order, $price);
        }, attempts: 3);
    }

    private function fill(Order $order, Decimal $price): SettlementOutcome
    {
        $before = OrderSnapshot::of($order);
        $notional = $order->quantity->times($price);

        if ($order->side === OrderSide::Buy && $notional->isGreaterThan($order->reserved_amount)) {
            return $this->reject($order, 'PRICE_MOVED_BEYOND_RESERVATION');
        }

        $wallet = Wallet::query()
            ->where('user_id', $order->user_id)
            ->where('currency', config('trading.wallet_currency'))
            ->lockForUpdate()
            ->firstOrFail();

        $position = $this->ledger->lockPosition($order->user_id, $order->asset_id);

        if ($order->side === OrderSide::Buy) {
            $this->ledger->spendReserved($wallet, $notional, $order);
            $this->ledger->release($wallet, $order->reserved_amount->minus($notional), $order);
            $this->ledger->receiveFilled($position, $order->quantity, $price, $order);
        } else {
            $this->ledger->deliverReserved($position, $order->quantity, $order);
            $this->ledger->creditProceeds($wallet, $notional, $order);
        }

        Execution::create([
            'order_id' => $order->id,
            'asset_id' => $order->asset_id,
            'price' => $price,
            'quantity' => $order->quantity,
            'notional' => $notional,
            'fee' => Decimal::zero(),
            'executed_at' => now(),
        ]);

        $order->status = OrderStatus::Filled;
        $order->filled_at = now()->toImmutable();
        $order->save();

        OrderFilled::dispatch($order->id, $before, OrderSnapshot::of($order), context: ['price' => $price->normalized()]);

        return SettlementOutcome::Filled;
    }

    private function reject(Order $order, string $reason): SettlementOutcome
    {
        $before = OrderSnapshot::of($order);

        ($this->release)($order);

        $order->status = OrderStatus::Rejected;
        $order->failure_reason = $reason;
        $order->save();

        OrderRejected::dispatch($order->id, $before, OrderSnapshot::of($order), context: ['reason' => $reason]);

        return SettlementOutcome::Rejected;
    }
}
