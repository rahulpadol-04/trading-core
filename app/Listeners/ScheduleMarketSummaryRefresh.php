<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderFilled;
use App\Jobs\RefreshMarketSummaryJob;
use Illuminate\Support\Facades\Cache;

/**
 * Debounced: Cache::add is an atomic SET NX EX, so only the first fill in each window
 * schedules a rebuild, delayed to the end of the window so it covers the whole burst.
 */
final class ScheduleMarketSummaryRefresh
{
    public function handle(OrderFilled $event): void
    {
        $debounce = config('trading.summary.refresh_debounce_seconds');

        if (Cache::add('market:summary:refresh-scheduled', true, $debounce)) {
            RefreshMarketSummaryJob::dispatch()->delay(now()->addSeconds($debounce));
        }
    }
}
