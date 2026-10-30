<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Market\BuildMarketSummaryAction;
use Illuminate\Support\Facades\Cache;

final class MarketSummaryCache
{
    public const string KEY = 'market:summary:v1';

    public const string LOCK = 'market:summary:rebuild';

    public function __construct(private readonly BuildMarketSummaryAction $builder) {}

    /**
     * Cache hit, or exactly one reader rebuilds while the others wait on the lock
     * and then read the value it stored.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $cached = Cache::get(self::KEY);

        if (is_array($cached)) {
            return $cached;
        }

        return Cache::lock(self::LOCK, config('trading.summary.lock_seconds'))
            ->block(5, function (): array {
                $cached = Cache::get(self::KEY);

                return is_array($cached) ? $cached : $this->rebuild();
            });
    }

    /**
     * Compute and overwrite. Never forget the key: readers keep the old value until
     * the new one lands, so a burst of fills cannot cause a cold-cache stampede.
     *
     * @return array<string, mixed>
     */
    public function rebuild(): array
    {
        $summary = $this->builder->execute();

        Cache::put(self::KEY, $summary, config('trading.summary.ttl_seconds'));

        return $summary;
    }
}
