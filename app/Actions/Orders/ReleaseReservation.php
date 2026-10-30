<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderSide;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Ledger;
use LogicException;

/**
 * Releases everything an open order still holds. The caller already holds the order's
 * row lock; this then locks the wallet or the position (order -> wallet -> position).
 */
final class ReleaseReservation
{
    public function __construct(private readonly Ledger $ledger) {}

    public function __invoke(Order $lockedOrder): void
    {
        if (! $lockedOrder->status->holdsReservation()) {
            throw new LogicException("Order {$lockedOrder->id} holds no reservation.");
        }

        if ($lockedOrder->side === OrderSide::Buy) {
            $wallet = Wallet::query()
                ->where('user_id', $lockedOrder->user_id)
                ->where('currency', config('trading.wallet_currency'))
                ->lockForUpdate()
                ->firstOrFail();

            $this->ledger->release($wallet, $lockedOrder->reserved_amount, $lockedOrder);
        } else {
            $position = $this->ledger->lockPosition($lockedOrder->user_id, $lockedOrder->asset_id);

            $this->ledger->releaseQuantity($position, $lockedOrder->quantity, $lockedOrder);
        }
    }
}
