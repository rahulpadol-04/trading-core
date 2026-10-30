<?php

declare(strict_types=1);

use App\Enums\LedgerBucket;
use App\Exceptions\Domain\InsufficientFunds;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Position;
use App\Services\Ledger;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('funds a wallet through the ledger', function () {
    $user = trader('1000');

    expect((string) wallet($user)->available_balance)->toBe('1000')
        ->and(LedgerEntry::where('wallet_id', wallet($user)->id)->count())->toBe(1);

    assertLedgerConsistent();
});

it('moves cash between buckets and records balance_after for each row', function () {
    $user = trader('1000');
    $order = Order::factory()->for($user)->create(['reserved_amount' => '200']);
    $ledger = app(Ledger::class);

    DB::transaction(function () use ($ledger, $user, $order) {
        $wallet = wallet($user)->lockFresh();
        $ledger->reserve($wallet, Decimal::of('200'), $order);
        $ledger->spendReserved($wallet, Decimal::of('194'), $order);
        $ledger->release($wallet, Decimal::of('6'), $order);
    });

    $wallet = wallet($user);
    expect((string) $wallet->available_balance)->toBe('806')
        ->and((string) $wallet->reserved_balance)->toBe('0')
        ->and(LedgerEntry::where('order_id', $order->id)->orderBy('id')->get()
            ->map(fn (LedgerEntry $e) => [$e->bucket, (string) $e->amount, (string) $e->balance_after])->all())
        ->toBe([
            [LedgerBucket::Available, '-200', '800'],
            [LedgerBucket::Reserved, '200', '200'],
            [LedgerBucket::Reserved, '-194', '6'],
            [LedgerBucket::Reserved, '-6', '0'],
            [LedgerBucket::Available, '6', '806'],
        ]);
});

it('refuses to reserve more than is available', function () {
    $user = trader('100');
    $order = Order::factory()->for($user)->create();

    DB::transaction(fn () => app(Ledger::class)->reserve(wallet($user)->lockFresh(), Decimal::of('100.01'), $order));
})->throws(InsufficientFunds::class);

it('lets the database reject a negative balance even if the application tried', function () {
    $wallet = wallet(trader('10'));

    DB::table('wallets')->where('id', $wallet->id)->update(['available_balance' => '-1']);
})->throws(QueryException::class, 'wallets_available_non_negative');

it('blocks updates to ledger entries in the database', function () {
    trader();

    DB::table('ledger_entries')->update(['amount' => '1']);
})->throws(QueryException::class, 'append-only');

it('blocks deletes of ledger entries in the database', function () {
    trader();

    DB::table('ledger_entries')->delete();
})->throws(QueryException::class, 'append-only');

it('blocks updates to ledger entries through the model', function () {
    trader();

    LedgerEntry::firstOrFail()->update(['amount' => '1']);
})->throws(LogicException::class);

it('creates a position row exactly once', function () {
    $user = trader();
    $asset = tradableAsset();

    DB::transaction(function () use ($user, $asset) {
        app(Ledger::class)->lockPosition($user->id, $asset->id);
        app(Ledger::class)->lockPosition($user->id, $asset->id);
    });

    expect(Position::where('user_id', $user->id)->where('asset_id', $asset->id)->count())->toBe(1);
});

it('re-weights the average cost on deposits', function () {
    $user = trader();
    $asset = tradableAsset();

    $position = DB::transaction(function () use ($user, $asset) {
        $ledger = app(Ledger::class);
        $position = $ledger->lockPosition($user->id, $asset->id);
        $ledger->depositQuantity($position, Decimal::of('1'), Decimal::of('100'));
        $ledger->depositQuantity($position, Decimal::of('3'), Decimal::of('120'));

        return $position;
    });

    expect((string) $position->fresh()->average_cost)->toBe('115')
        ->and((string) $position->fresh()->quantity)->toBe('4');

    assertLedgerConsistent();
});
