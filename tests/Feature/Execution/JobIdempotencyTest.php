<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ProcessOrderJob;
use App\Models\Execution;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Queue;

it('settles an order once even if ProcessOrderJob runs twice', function () {
    Queue::fake([ProcessOrderJob::class]);
    $user = trader('1000');
    $asset = tradableAsset();
    setPrice($asset, '97');
    $order = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '2', '100');

    $job = new ProcessOrderJob($order->id);
    $job->handle(app(SettleOrderAction::class));
    $job->handle(app(SettleOrderAction::class));

    expect(Execution::where('order_id', $order->id)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Filled)
        ->and((string) wallet($user)->available_balance)->toBe('806')
        ->and((string) wallet($user)->reserved_balance)->toBe('0')
        ->and(LedgerEntry::where('order_id', $order->id)->where('type', 'TRADE')->count())->toBe(1);

    assertLedgerConsistent();
});

it('fills through the queue as soon as placement commits when the price already crosses', function () {
    $user = trader('1000');
    $asset = tradableAsset();
    setPrice($asset, '97');

    $order = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '2', '100');

    expect($order->status)->toBe(OrderStatus::Filled);
    assertLedgerConsistent();
});
