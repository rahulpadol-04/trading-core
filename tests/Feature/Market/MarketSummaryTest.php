<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ProcessOrderJob;
use App\Jobs\RefreshMarketSummaryJob;
use App\Models\Asset;
use App\Models\Execution;
use App\Models\MarketTick;
use App\Models\Order;
use App\Services\MarketSummaryCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function tick(Asset $asset, string $price, CarbonImmutable $at): void
{
    MarketTick::query()->insert(['asset_id' => $asset->id, 'price' => $price, 'recorded_at' => $at->format('Y-m-d H:i:s.uP')]);
}

function execution(Asset $asset, string $notional, CarbonImmutable $at): void
{
    $order = Order::factory()->for($asset)->status(OrderStatus::Filled)->create(['filled_at' => $at]);

    Execution::query()->create([
        'order_id' => $order->id,
        'asset_id' => $asset->id,
        'price' => $notional,
        'quantity' => '1',
        'notional' => $notional,
        'executed_at' => $at,
    ]);
}

it('requires authentication', function () {
    $this->getJson('/api/v1/market/summary')->assertUnauthorized();
});

it('reports filled orders, top assets by notional and the 24 h change', function () {
    $now = CarbonImmutable::now();
    $alpha = Asset::factory()->create(['symbol' => 'ALPHA']);
    $beta = Asset::factory()->create(['symbol' => 'BETA']);
    tick($alpha, '50', $now->subHours(25));
    tick($alpha, '100', $now->subHours(23));
    tick($alpha, '110', $now->subMinute());
    tick($beta, '10', $now->subHours(25));
    execution($alpha, '300', $now->subHours(2));
    execution($alpha, '200', $now->subHours(1));
    execution($beta, '1000', $now->subMinutes(5));
    execution($alpha, '5000', $now->subDays(2));
    Sanctum::actingAs(trader());

    $this->getJson('/api/v1/market/summary')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=5, private')
        ->assertJsonPath('data.filled_orders', 3)
        ->assertJsonPath('data.top_assets_by_volume', [
            ['asset' => 'BETA', 'notional_volume' => '1000'],
            ['asset' => 'ALPHA', 'notional_volume' => '500'],
        ])
        ->assertJsonPath('data.assets', [
            ['asset' => 'ALPHA', 'last_price' => '110', 'change_24h_pct' => '10'],
            ['asset' => 'BETA', 'last_price' => '10', 'change_24h_pct' => null],
        ]);
});

it('lists at most five assets by volume', function () {
    foreach (range(1, 6) as $i) {
        execution(Asset::factory()->create(), (string) ($i * 100), CarbonImmutable::now()->subHour());
    }
    Sanctum::actingAs(trader());

    $this->getJson('/api/v1/market/summary')
        ->assertJsonCount(5, 'data.top_assets_by_volume')
        ->assertJsonPath('data.top_assets_by_volume.0.notional_volume', '600')
        ->assertJsonPath('data.top_assets_by_volume.4.notional_volume', '200');
});

it('serves repeat reads from the cache without touching the database', function () {
    Sanctum::actingAs(trader());
    $this->getJson('/api/v1/market/summary')->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/v1/market/summary')->assertOk();

    expect(DB::getQueryLog())->toBe([]);
});

it('caches for at most 60 seconds', function () {
    $first = app(MarketSummaryCache::class)->get();
    execution(Asset::factory()->create(), '10', CarbonImmutable::now());

    $store = Cache::store()->getStore();
    $ttl = $store->connection()->ttl($store->getPrefix().MarketSummaryCache::KEY);

    expect(app(MarketSummaryCache::class)->get())->toBe($first)
        ->and($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('schedules one debounced refresh for a burst of fills', function () {
    Queue::fake([ProcessOrderJob::class, RefreshMarketSummaryJob::class]);
    $user = trader('10000');
    $asset = tradableAsset();
    setPrice($asset, '10');

    foreach (range(1, 3) as $i) {
        $order = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '1', '10');
        app(SettleOrderAction::class)->execute($order->id);
    }

    Queue::assertPushed(RefreshMarketSummaryJob::class, 1);
});

it('overwrites the cached summary when a fill triggers a refresh', function () {
    $stale = app(MarketSummaryCache::class)->get();
    $user = trader('10000');
    $asset = tradableAsset();
    setPrice($asset, '10');

    placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '1', '10');

    expect(Cache::get(MarketSummaryCache::KEY)['filled_orders'])->toBe($stale['filled_orders'] + 1);
});
