<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Services\PriceStore;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class MatchAssetOrdersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 10;

    public function __construct(public readonly int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(PriceStore $prices): void
    {
        $price = $prices->fresh($this->assetId, config('trading.price_max_age_seconds'));

        if ($price === null) {
            return;
        }

        $this->dispatchCrossing(OrderSide::Buy, '>=', $price->toStorage());
        $this->dispatchCrossing(OrderSide::Sell, '<=', $price->toStorage());
    }

    private function dispatchCrossing(OrderSide $side, string $operator, string $price): void
    {
        Order::query()
            ->where('asset_id', $this->assetId)
            ->where('side', $side)
            ->where('status', OrderStatus::Pending)
            ->where('type', OrderType::Limit)
            ->where('limit_price', $operator, $price)
            ->select('id')
            ->lazyById(1000)
            ->each(fn (Order $order) => ProcessOrderJob::dispatch($order->id));
    }
}
