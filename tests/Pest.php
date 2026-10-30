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
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
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

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () {
        Redis::flushdb();
        Cache::flush();
    })
    ->group('concurrency')
    ->in('Concurrency');

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

/**
 * A wall-clock instant far enough ahead for every worker process to boot.
 */
function startInstant(): string
{
    return (string) (microtime(true) + 3.0);
}

/**
 * Run worker scripts as separate OS processes, each with its own PHP runtime and
 * database connection, and return their outputs in the order given.
 *
 * @param  list<array<int, string|int>>  $workers  [script, ...arguments]
 * @return list<string>
 */
function runTogether(array $workers): array
{
    $results = Process::pool(function (Pool $pool) use ($workers): void {
        foreach ($workers as $worker) {
            $script = array_shift($worker);

            $pool->path(base_path())
                ->env(['QUEUE_CONNECTION' => 'null'])
                ->timeout(60)
                ->command(['php', "tests/Concurrency/workers/{$script}", ...array_map(strval(...), $worker)]);
        }
    })->start()->wait();

    return array_map(function (int $i) use ($results): string {
        expect($results[$i]->successful())->toBeTrue($results[$i]->errorOutput());

        return $results[$i]->output();
    }, array_keys($workers));
}
