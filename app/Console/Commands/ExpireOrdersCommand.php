<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Orders\CancelOrderAction;
use App\Enums\OrderStatus;
use App\Exceptions\Domain\OrderNotCancellable;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('orders:expire')]
#[Description('Cancel PENDING orders whose expires_at has passed and release their reservations')]
class ExpireOrdersCommand extends Command
{
    public function handle(CancelOrderAction $cancel): int
    {
        $expired = 0;

        Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('expires_at', '<=', now())
            ->select('id')
            ->chunkById(500, function (Collection $orders) use ($cancel, &$expired): void {
                foreach ($orders as $order) {
                    try {
                        $cancel->execute($order, CancelOrderAction::REASON_EXPIRED, null, null);
                        $expired++;
                    } catch (OrderNotCancellable) {
                        // Filled between the scan and the lock: the fill won, which is correct.
                    }
                }
            });

        $this->info("Expired {$expired} orders.");

        return self::SUCCESS;
    }
}
