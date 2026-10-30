<?php

declare(strict_types=1);

use App\Models\LedgerEntry;
use App\Models\Order;

it('accepts exactly 5 of 20 simultaneous BUY orders for a wallet that affords 5', function () {
    $user = trader('500');
    $asset = tradableAsset();
    $startAt = startInstant();

    $outputs = runTogether(array_map(
        fn (int $i): array => ['place_order.php', $user->id, $asset->id, "concurrent-{$i}", $startAt],
        range(1, 20),
    ));

    $outcomes = collect($outputs)->map(fn (string $output) => json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    $wallet = wallet($user);

    expect($outcomes->where('result', 'accepted'))->toHaveCount(5)
        ->and($outcomes->where('result', 'rejected')->pluck('code')->unique()->all())->toBe(['INSUFFICIENT_FUNDS'])
        ->and(Order::where('user_id', $user->id)->count())->toBe(5)
        ->and((string) $wallet->available_balance)->toBe('0')
        ->and((string) $wallet->reserved_balance)->toBe('500')
        ->and(LedgerEntry::where('wallet_id', $wallet->id)->where('balance_after', '<', 0)->exists())->toBeFalse();

    assertLedgerConsistent();
});

it('creates one order for 10 simultaneous retries with the same Idempotency-Key', function () {
    $user = trader('500');
    $asset = tradableAsset();
    $startAt = startInstant();

    $outputs = runTogether(array_fill(0, 10, ['place_order.php', $user->id, $asset->id, 'same-key-retry', $startAt]));

    expect(collect($outputs)->map(fn (string $output) => json_decode($output, true)['result'])->unique()->all())->toBe(['accepted'])
        ->and(Order::where('user_id', $user->id)->count())->toBe(1)
        ->and((string) wallet($user)->reserved_balance)->toBe('100');

    assertLedgerConsistent();
});
