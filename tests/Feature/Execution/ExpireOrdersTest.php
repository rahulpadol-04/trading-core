<?php

declare(strict_types=1);

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ProcessOrderJob;
use Illuminate\Support\Facades\Queue;

it('cancels overdue PENDING orders and releases their reservations', function () {
    Queue::fake([ProcessOrderJob::class]);
    $user = trader('1000');
    $asset = tradableAsset();
    $overdue = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    $this->travel(2)->days();
    $fresh = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '1', '100');

    $this->artisan('orders:expire')->expectsOutput('Expired 1 orders.')->assertSuccessful();

    expect($overdue->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($overdue->fresh()->failure_reason)->toBe('EXPIRED')
        ->and($fresh->fresh()->status)->toBe(OrderStatus::Pending)
        ->and((string) wallet($user)->reserved_balance)->toBe('100');

    assertLedgerConsistent();
});
