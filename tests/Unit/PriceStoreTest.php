<?php

declare(strict_types=1);

use App\Services\PriceStore;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->prices = app(PriceStore::class);
});

it('stores price and time together with microsecond precision', function () {
    $at = CarbonImmutable::parse('2026-10-30 10:00:00.123456');
    $this->prices->put(7, Decimal::of('65012.40'), $at);

    $latest = $this->prices->latest(7);

    expect((string) $latest['price'])->toBe('65012.4')
        ->and($latest['at']->format('Y-m-d H:i:s.u'))->toBe('2026-10-30 10:00:00.123456');
});

it('treats a price as fresh up to the max age and stale after it', function (int $ageSeconds, bool $fresh) {
    $this->freezeTime();
    $this->prices->put(1, Decimal::of('100'), CarbonImmutable::now());
    $this->travel($ageSeconds)->seconds();

    expect($this->prices->fresh(1, 30) !== null)->toBe($fresh);
})->with([
    '29 s old' => [29, true],
    '30 s old' => [30, true],
    '31 s old' => [31, false],
]);

it('returns null for an asset without a price', function () {
    expect($this->prices->latest(999))->toBeNull()
        ->and($this->prices->fresh(999, 30))->toBeNull();
});

it('writes many prices in one call', function () {
    $now = CarbonImmutable::now();
    $this->prices->putMany([1 => [Decimal::of('1.5'), $now], 2 => [Decimal::of('2.5'), $now]]);

    expect((string) $this->prices->fresh(1, 30))->toBe('1.5')
        ->and((string) $this->prices->fresh(2, 30))->toBe('2.5');
});
