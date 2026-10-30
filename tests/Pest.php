<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Redis::flushdb();
        Cache::flush();
    })
    ->in('Feature');

function trader(string $funds = '1000'): User
{
    $user = User::factory()->create();
    Wallet::factory()->for($user)->funded($funds)->create();

    return $user;
}

function tradableAsset(int $pricePrecision = 2, int $quantityPrecision = 4): Asset
{
    return Asset::factory()->create([
        'price_precision' => $pricePrecision,
        'quantity_precision' => $quantityPrecision,
    ]);
}

function wallet(User $user): Wallet
{
    return $user->wallet()->firstOrFail();
}

function assertLedgerConsistent(): void
{
    expect(Artisan::call('ledger:verify'))->toBe(0, Artisan::output());
}
