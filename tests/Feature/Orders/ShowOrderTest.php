<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Order;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = trader('1000');
    $this->asset = tradableAsset();
});

it('requires authentication', function () {
    $order = Order::factory()->for($this->user)->create();

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertUnauthorized();
});

it('shows an own order with its executions', function () {
    Queue::fake();
    setPrice($this->asset, '97');
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    app(SettleOrderAction::class)->execute($order->id);
    Sanctum::actingAs($this->user);

    $this->getJson("/api/v1/orders/{$order->uuid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'FILLED')
        ->assertJsonPath('data.executions.0.price', '97')
        ->assertJsonPath('data.executions.0.quantity', '2')
        ->assertJsonPath('data.executions.0.notional', '194')
        ->assertJsonPath('data.executions.0.fee', '0');
});

it('returns 404 for another trader order, an unknown id and a malformed id', function (Closure $id) {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/orders/'.$id())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
})->with([
    'another trader' => [fn () => Order::factory()->for(trader())->create()->uuid],
    'unknown uuid' => [fn () => (string) Str::uuid()],
    'malformed id' => [fn () => '42'],
]);
