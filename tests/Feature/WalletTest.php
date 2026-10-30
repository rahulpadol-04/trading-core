<?php

declare(strict_types=1);

use App\Enums\OrderSide;
use App\Enums\OrderType;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/v1/wallet')->assertUnauthorized();
});

it('shows balances as strings and open positions with the latest price', function () {
    Queue::fake();
    $user = trader('1000');
    $held = tradableAsset();
    $empty = tradableAsset();
    holding($user, $held, '5', '90');
    holding($user, $empty, '0.0001', '10');
    placeOrder($user, $held, OrderSide::Sell, OrderType::Limit, '2', '120');
    placeOrder($user, $held, OrderSide::Buy, OrderType::Limit, '1', '80');
    setPrice($held, '101.5');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/wallet')
        ->assertOk()
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.available_balance', '920')
        ->assertJsonPath('data.reserved_balance', '80')
        ->assertJsonPath('data.total_balance', '1000')
        ->assertJsonCount(2, 'data.positions')
        ->assertJsonPath('data.positions.0.asset', $held->symbol)
        ->assertJsonPath('data.positions.0.quantity', '3')
        ->assertJsonPath('data.positions.0.reserved_quantity', '2')
        ->assertJsonPath('data.positions.0.average_cost', '90')
        ->assertJsonPath('data.positions.0.last_price', '101.5')
        ->assertJsonPath('data.positions.1.last_price', null);
});
