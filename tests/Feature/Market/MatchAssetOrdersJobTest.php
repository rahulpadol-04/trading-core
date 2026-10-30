<?php

declare(strict_types=1);

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\MatchAssetOrdersJob;
use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use App\Services\PriceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = trader('10000');
    $this->asset = tradableAsset();
    holding($this->user, $this->asset, '10');
});

it('dispatches settlement only for PENDING LIMIT orders the price crosses', function () {
    $buyAbove = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '1', '100');
    placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '1', '90');
    $sellAt = placeOrder($this->user, $this->asset, OrderSide::Sell, OrderType::Limit, '1', '95');
    placeOrder($this->user, $this->asset, OrderSide::Sell, OrderType::Limit, '1', '110');
    Order::factory()->for($this->user)->for($this->asset)->status(OrderStatus::Filled)->create(['limit_price' => '200']);
    Order::factory()->for($this->user)->for(tradableAsset())->create(['limit_price' => '200']);
    setPrice($this->asset, '95');
    Queue::fake([ProcessOrderJob::class]);

    (new MatchAssetOrdersJob($this->asset->id))->handle(app(PriceStore::class));

    Queue::assertPushed(ProcessOrderJob::class, 2);
    Queue::assertPushed(ProcessOrderJob::class, fn (ProcessOrderJob $job) => $job->orderId === $buyAbove->id);
    Queue::assertPushed(ProcessOrderJob::class, fn (ProcessOrderJob $job) => $job->orderId === $sellAt->id);
});

it('does nothing without a fresh price', function () {
    placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '1', '100');
    setPrice($this->asset, '50', CarbonImmutable::now()->subMinute());
    Queue::fake([ProcessOrderJob::class]);

    (new MatchAssetOrdersJob($this->asset->id))->handle(app(PriceStore::class));

    Queue::assertNothingPushed();
});

it('fills crossed orders end to end through the queue', function () {
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '1', '100');
    setPrice($this->asset, '99.5');

    MatchAssetOrdersJob::dispatch($this->asset->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Filled);
    assertLedgerConsistent();
});
