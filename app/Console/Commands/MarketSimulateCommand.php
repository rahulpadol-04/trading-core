<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MatchAssetOrdersJob;
use App\Models\Asset;
use App\Models\MarketTick;
use App\Services\PriceStore;
use App\Support\Decimal;
use App\Support\RandomWalk;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:simulate
    {--rate=50 : Total ticks per second across all assets}
    {--flush-ms=200 : Batch window; ticks are inserted once per window}
    {--duration=0 : Stop after N seconds (0 runs until interrupted)}')]
#[Description('Generate random-walk ticks in batches, publish latest prices and trigger matching')]
class MarketSimulateCommand extends Command
{
    private bool $running = true;

    public function handle(PriceStore $prices): int
    {
        $rate = max(1, (int) $this->option('rate'));
        $windowMicros = max(10, (int) $this->option('flush-ms')) * 1000;
        $perWindow = max(1, (int) round($rate * $windowMicros / 1_000_000));
        $duration = (int) $this->option('duration');
        $stopAt = $duration > 0 ? microtime(true) + $duration : null;

        $this->trap([SIGINT, SIGTERM], function (): void {
            $this->running = false;
        });

        $assets = Asset::query()->where('is_tradable', true)->orderBy('id')->get(['id', 'symbol', 'price_precision']);

        if ($assets->isEmpty()) {
            $this->error('No tradable assets. Run the seeders first.');

            return self::FAILURE;
        }

        $last = [];
        foreach ($assets as $asset) {
            $stored = MarketTick::query()->where('asset_id', $asset->id)->latest('recorded_at')->value('price');
            $last[$asset->id] = $prices->latest($asset->id)['price'] ?? Decimal::of(is_string($stored) ? $stored : '100');
        }

        $this->info("Simulating {$rate} ticks/s across {$assets->count()} assets, {$perWindow} ticks per batch.");

        while ($this->running && ($stopAt === null || microtime(true) < $stopAt)) {
            $windowStart = microtime(true);
            $now = CarbonImmutable::now();
            $rows = [];
            $latest = [];

            for ($i = 0; $i < $perWindow; $i++) {
                $asset = $assets[$i % $assets->count()];
                $price = RandomWalk::step($last[$asset->id], $asset->price_precision);
                $at = $now->addMicroseconds(intdiv($i * $windowMicros, $perWindow));

                $last[$asset->id] = $price;
                $latest[$asset->id] = [$price, $at];
                $rows[] = [
                    'asset_id' => $asset->id,
                    'price' => $price->toStorage(),
                    'recorded_at' => $at->format('Y-m-d H:i:s.uP'),
                ];
            }

            MarketTick::query()->insert($rows);
            $prices->putMany($latest);

            foreach (array_keys($latest) as $assetId) {
                MatchAssetOrdersJob::dispatch($assetId);
            }

            $elapsedMicros = (int) ((microtime(true) - $windowStart) * 1_000_000);

            if ($elapsedMicros < $windowMicros) {
                usleep($windowMicros - $elapsedMicros);
            } else {
                $this->warn('Falling behind: a batch took longer than its window.');
            }
        }

        $this->info('Simulator stopped.');

        return self::SUCCESS;
    }
}
