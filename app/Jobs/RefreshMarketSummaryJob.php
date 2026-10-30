<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MarketSummaryCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RefreshMarketSummaryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(MarketSummaryCache $cache): void
    {
        Cache::lock(MarketSummaryCache::LOCK, config('trading.summary.lock_seconds'))
            ->get(fn (): array => $cache->rebuild());
    }
}
