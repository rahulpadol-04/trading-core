<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\MarketTick;
use App\Services\PriceStore;
use App\Support\Decimal;
use App\Support\RandomWalk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class MarketTickSeeder extends Seeder
{
    /**
     * One tick per minute for 25 hours per asset, so the 24 h change has a baseline.
     */
    public function run(PriceStore $prices): void
    {
        $now = CarbonImmutable::now();

        foreach (AssetSeeder::ASSETS as [$symbol, , $pricePrecision, , $startPrice]) {
            $asset = Asset::query()->where('symbol', $symbol)->firstOrFail();
            $price = Decimal::of($startPrice);
            $rows = [];

            for ($at = $now->subHours(25); $at <= $now; $at = $at->addMinute()) {
                $price = RandomWalk::step($price, $pricePrecision);
                $rows[] = ['asset_id' => $asset->id, 'price' => $price->toStorage(), 'recorded_at' => $at->format('Y-m-d H:i:s.uP')];

                if (count($rows) === 1000) {
                    MarketTick::query()->insert($rows);
                    $rows = [];
                }
            }

            MarketTick::query()->insert($rows);
            $prices->put($asset->id, $price, $now);
        }
    }
}
