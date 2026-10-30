<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SettlementOutcome;
use App\Jobs\ProcessOrderJob;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([ProcessOrderJob::class]);
    $this->user = trader('1000');
    $this->asset = tradableAsset();
    $this->settle = fn (int $orderId): SettlementOutcome => app(SettleOrderAction::class)->execute($orderId);
});

function position(Model $user, Model $asset): Position
{
    return Position::where('user_id', $user->id)->where('asset_id', $asset->id)->firstOrFail();
}

it('fills a BUY LIMIT at the market price below the limit and releases the difference', function () {
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    setPrice($this->asset, '97');

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Filled);

    $order->refresh();
    $execution = Execution::where('order_id', $order->id)->sole();
    expect($order->status)->toBe(OrderStatus::Filled)
        ->and($order->filled_at)->not->toBeNull()
        ->and((string) $execution->price)->toBe('97')
        ->and((string) $execution->notional)->toBe('194')
        ->and((string) wallet($this->user)->available_balance)->toBe('806')
        ->and((string) wallet($this->user)->reserved_balance)->toBe('0')
        ->and((string) position($this->user, $this->asset)->quantity)->toBe('2')
        ->and((string) position($this->user, $this->asset)->average_cost)->toBe('97');

    $audit = AuditLog::where('action', 'order.filled')->sole();
    expect($audit->actor_type)->toBe('SYSTEM')
        ->and($audit->context['price'])->toBe('97')
        ->and($audit->context['before']['status'])->toBe('PENDING')
        ->and($audit->context['after']['status'])->toBe('FILLED');

    assertLedgerConsistent();
});

it('leaves a BUY LIMIT pending while the price is above the limit', function () {
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    setPrice($this->asset, '100.01');

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Waiting)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and((string) wallet($this->user)->reserved_balance)->toBe('200');
});

it('never fills a LIMIT order on a stale price', function () {
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    setPrice($this->asset, '90', CarbonImmutable::now()->subSeconds(31));

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Waiting);
});

it('fills a SELL LIMIT at or above the limit and credits the proceeds', function () {
    holding($this->user, $this->asset, '5', '80');
    $order = placeOrder($this->user, $this->asset, OrderSide::Sell, OrderType::Limit, '3', '100');
    setPrice($this->asset, '101');

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Filled);

    $position = position($this->user, $this->asset);
    expect((string) $position->quantity)->toBe('2')
        ->and((string) $position->reserved_quantity)->toBe('0')
        ->and((string) $position->average_cost)->toBe('80')
        ->and((string) wallet($this->user)->available_balance)->toBe('1303');

    assertLedgerConsistent();
});

it('resets the average cost when a position is sold out', function () {
    holding($this->user, $this->asset, '1', '80');
    $order = placeOrder($this->user, $this->asset, OrderSide::Sell, OrderType::Limit, '1', '100');
    setPrice($this->asset, '100');

    ($this->settle)($order->id);

    expect((string) position($this->user, $this->asset)->average_cost)->toBe('0');
});

it('re-weights the average cost after two buys', function () {
    setPrice($this->asset, '100');
    ($this->settle)(placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '1', '100')->id);
    setPrice($this->asset, '130');
    ($this->settle)(placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '3', '130')->id);

    expect((string) position($this->user, $this->asset)->average_cost)->toBe('122.5')
        ->and((string) position($this->user, $this->asset)->quantity)->toBe('4');
    assertLedgerConsistent();
});

it('fills a MARKET BUY at the latest price and releases the unused buffer', function () {
    setPrice($this->asset, '100');
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Market, '2');
    expect((string) $order->reserved_amount)->toBe('210');
    setPrice($this->asset, '102');

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Filled)
        ->and((string) wallet($this->user)->available_balance)->toBe('796')
        ->and((string) wallet($this->user)->reserved_balance)->toBe('0');
    assertLedgerConsistent();
});

it('rejects a MARKET BUY when the price jumped beyond the reservation', function () {
    setPrice($this->asset, '100');
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Market, '2');
    setPrice($this->asset, '105.01');

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Rejected);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Rejected)
        ->and($order->failure_reason)->toBe('PRICE_MOVED_BEYOND_RESERVATION')
        ->and((string) wallet($this->user)->available_balance)->toBe('1000')
        ->and(Execution::count())->toBe(0);
    assertLedgerConsistent();
});

it('rejects a MARKET order whose price went stale before execution', function () {
    holding($this->user, $this->asset, '1');
    setPrice($this->asset, '100');
    $order = placeOrder($this->user, $this->asset, OrderSide::Sell, OrderType::Market, '1');
    $this->travel(31)->seconds();

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Rejected)
        ->and($order->fresh()->failure_reason)->toBe('NO_RECENT_PRICE')
        ->and((string) position($this->user, $this->asset)->quantity)->toBe('1');
    assertLedgerConsistent();
});

it('expires a late order instead of filling it', function () {
    $order = placeOrder($this->user, $this->asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    $this->travel(2)->days();
    setPrice($this->asset, '90');

    expect(($this->settle)($order->id))->toBe(SettlementOutcome::Expired);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->failure_reason)->toBe('EXPIRED')
        ->and((string) wallet($this->user)->available_balance)->toBe('1000')
        ->and(Execution::count())->toBe(0);
    assertLedgerConsistent();
});
