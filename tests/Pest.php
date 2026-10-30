<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrderAction;
use App\DTOs\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Position;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\PriceStore;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
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

function setPrice(Asset $asset, string $price, ?CarbonImmutable $at = null): void
{
    app(PriceStore::class)->put($asset->id, Decimal::of($price), $at ?? CarbonImmutable::now());
}

function placeOrder(
    User $user,
    Asset $asset,
    OrderSide $side,
    OrderType $type,
    string $quantity,
    ?string $limitPrice = null,
    ?string $key = null,
): Order {
    $result = app(PlaceOrderAction::class)->execute(new PlaceOrderData(
        userId: $user->id,
        assetId: $asset->id,
        side: $side,
        type: $type,
        quantity: Decimal::of($quantity),
        limitPrice: $limitPrice === null ? null : Decimal::of($limitPrice),
        expiresAt: null,
        idempotencyKey: $key ?? (string) Str::uuid(),
        requestIp: '127.0.0.1',
    ));

    return Order::findOrFail($result->orderId);
}

function holding(User $user, Asset $asset, string $quantity, string $cost = '100'): Position
{
    return DB::transaction(function () use ($user, $asset, $quantity, $cost): Position {
        $ledger = app(Ledger::class);
        $position = $ledger->lockPosition($user->id, $asset->id);
        $ledger->depositQuantity($position, Decimal::of($quantity), Decimal::of($cost));

        return $position;
    });
}

function assertLedgerConsistent(): void
{
    expect(Artisan::call('ledger:verify'))->toBe(0, Artisan::output());
}
