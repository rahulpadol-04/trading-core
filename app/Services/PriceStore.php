<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Latest price per asset in one Redis hash: prices:latest {assetId => "price|unixMicros"}.
 * Price and timestamp share one field so they can never disagree.
 */
final class PriceStore
{
    private const string KEY = 'prices:latest';

    public function put(int $assetId, Decimal $price, CarbonImmutable $at): void
    {
        Redis::hset(self::KEY, (string) $assetId, self::encode($price, $at));
    }

    /**
     * @param  array<int, array{Decimal, CarbonImmutable}>  $prices  assetId => [price, at]
     */
    public function putMany(array $prices): void
    {
        $fields = [];

        foreach ($prices as $assetId => [$price, $at]) {
            $fields[(string) $assetId] = self::encode($price, $at);
        }

        if ($fields !== []) {
            Redis::hmset(self::KEY, $fields);
        }
    }

    /**
     * @return array{price: Decimal, at: CarbonImmutable}|null
     */
    public function latest(int $assetId): ?array
    {
        $raw = Redis::hget(self::KEY, (string) $assetId);

        if (! is_string($raw) || ! str_contains($raw, '|')) {
            return null;
        }

        [$price, $micros] = explode('|', $raw, 2);
        $at = CarbonImmutable::createFromFormat('U.u', substr($micros, 0, -6).'.'.substr($micros, -6));

        return $at === null ? null : ['price' => Decimal::of($price), 'at' => $at];
    }

    /**
     * The latest price only if it is newer than $maxAgeSeconds.
     */
    public function fresh(int $assetId, int $maxAgeSeconds): ?Decimal
    {
        $latest = $this->latest($assetId);

        if ($latest === null || $latest['at']->lt(CarbonImmutable::now()->subSeconds($maxAgeSeconds))) {
            return null;
        }

        return $latest['price'];
    }

    private static function encode(Decimal $price, CarbonImmutable $at): string
    {
        return $price->normalized().'|'.$at->format('Uu');
    }
}
