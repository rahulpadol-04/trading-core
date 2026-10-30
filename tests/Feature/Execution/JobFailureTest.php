<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ProcessOrderJob;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

it('marks the order FAILED and releases the reservation on permanent failure', function () {
    $user = trader('1000');
    $asset = tradableAsset();
    $order = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    expect($order->status)->toBe(OrderStatus::Pending);

    Redis::shouldReceive('hget')->andThrow(new RedisException('connection lost'));

    expect(fn () => ProcessOrderJob::dispatch($order->id))->toThrow(RedisException::class);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->failure_reason)->toBe('PROCESSING_FAILED: RedisException')
        ->and((string) wallet($user)->available_balance)->toBe('1000')
        ->and((string) wallet($user)->reserved_balance)->toBe('0');

    assertLedgerConsistent();
});

it('does not overwrite an order that was already filled when failed() runs late', function () {
    Queue::fake([ProcessOrderJob::class]);
    $user = trader('1000');
    $asset = tradableAsset();
    setPrice($asset, '97');
    $order = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    app(SettleOrderAction::class)->execute($order->id);
    $releases = LedgerEntry::where('order_id', $order->id)->where('type', 'RELEASE')->count();

    (new ProcessOrderJob($order->id))->failed(new RuntimeException('late'));

    expect($order->fresh()->status)->toBe(OrderStatus::Filled)
        ->and(LedgerEntry::where('order_id', $order->id)->where('type', 'RELEASE')->count())->toBe($releases);
    assertLedgerConsistent();
});

it('completes business rejections without throwing so the queue does not retry them', function () {
    Queue::fake([ProcessOrderJob::class]);
    $user = trader('1000');
    $asset = tradableAsset();
    holding($user, $asset, '1');
    setPrice($asset, '100');
    $order = placeOrder($user, $asset, OrderSide::Sell, OrderType::Market, '1');
    $this->travel(31)->seconds();

    (new ProcessOrderJob($order->id))->handle(app(SettleOrderAction::class));

    expect($order->fresh()->status)->toBe(OrderStatus::Rejected);
});
