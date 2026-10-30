<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ProcessOrderJob;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake([ProcessOrderJob::class]);
    $this->user = trader('1000');
    $this->asset = tradableAsset();
    $this->order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '2', '100');
});

it('requires authentication', function () {
    $this->deleteJson("/api/v1/orders/{$this->order->uuid}")->assertUnauthorized();
});

it('cancels a pending order and releases the reservation once', function () {
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/orders/{$this->order->uuid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')
        ->assertJsonPath('data.failure_reason', null);

    expect((string) wallet($this->user)->available_balance)->toBe('1000')
        ->and((string) wallet($this->user)->reserved_balance)->toBe('0')
        ->and(LedgerEntry::where('order_id', $this->order->id)->where('type', 'RELEASE')->count())->toBe(2);

    $audit = AuditLog::where('action', 'order.cancelled')->sole();
    expect($audit->actor_id)->toBe($this->user->id)
        ->and($audit->actor_type)->toBe('USER')
        ->and($audit->ip_address)->toBe('127.0.0.1')
        ->and($audit->context['before']['status'])->toBe('PENDING')
        ->and($audit->context['after']['status'])->toBe('CANCELLED');

    assertLedgerConsistent();
});

it('treats a repeated DELETE as a no-op', function () {
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/orders/{$this->order->uuid}")->assertOk();
    $this->deleteJson("/api/v1/orders/{$this->order->uuid}")->assertOk()->assertJsonPath('data.status', 'CANCELLED');

    expect(LedgerEntry::where('order_id', $this->order->id)->where('type', 'RELEASE')->count())->toBe(2)
        ->and((string) wallet($this->user)->available_balance)->toBe('1000');
    assertLedgerConsistent();
});

it('hides other traders orders as not found and releases nothing', function () {
    Sanctum::actingAs(trader());

    $this->deleteJson("/api/v1/orders/{$this->order->uuid}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and((string) wallet($this->user)->reserved_balance)->toBe('200');
});

it('refuses to cancel a filled order', function () {
    setPrice($this->asset, '99');
    app(SettleOrderAction::class)->execute($this->order->id);
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/orders/{$this->order->uuid}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ORDER_NOT_CANCELLABLE')
        ->assertJsonPath('error.details.status', 'FILLED');

    assertLedgerConsistent();
});

it('releases reserved quantity when a SELL order is cancelled', function () {
    $position = holding($this->user, $this->asset, '5');
    $sell = placeOrder($this->user, $this->asset, OrderSide::Sell, OrderType::Limit, '4', '150');
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/orders/{$sell->uuid}")->assertOk();

    $position->refresh();
    expect((string) $position->quantity)->toBe('5')
        ->and((string) $position->reserved_quantity)->toBe('0');
    assertLedgerConsistent();
});
