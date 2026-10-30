<?php

declare(strict_types=1);

use App\Jobs\MatchAssetOrdersJob;
use App\Models\MarketTick;
use App\Services\PriceStore;
use Illuminate\Support\Facades\Queue;

it('writes batched ticks, publishes the latest prices and triggers one matching job per asset', function () {
    Queue::fake([MatchAssetOrdersJob::class]);
    $assets = collect([tradableAsset(), tradableAsset(5, 2), tradableAsset()]);
    tradableAsset()->update(['is_tradable' => false]);

    $this->artisan('market:simulate', ['--rate' => 60, '--flush-ms' => 100, '--duration' => 1])->assertSuccessful();

    expect(MarketTick::count())->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(70)
        ->and(MarketTick::query()->whereNotIn('asset_id', $assets->pluck('id'))->count())->toBe(0);

    foreach ($assets as $asset) {
        expect(app(PriceStore::class)->fresh($asset->id, 30))->not->toBeNull();
    }

    Queue::assertPushed(MatchAssetOrdersJob::class, $assets->count());
});

it('fails without tradable assets', function () {
    $this->artisan('market:simulate', ['--duration' => 1])->assertFailed();
});
