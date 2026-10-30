<?php

declare(strict_types=1);

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\ProcessOrderJob;
use App\Models\Execution;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Queue;

it('lets exactly one of cancel and fill win and releases exactly once', function () {
    Queue::fake([ProcessOrderJob::class]);
    $user = trader('10000');
    $asset = tradableAsset();
    $orders = collect(range(1, 10))->map(fn () => placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '1', '100'));
    setPrice($asset, '95');
    $startAt = startInstant();

    $workers = [];
    foreach ($orders as $order) {
        $workers[] = ['race.php', 'cancel', $order->id, $user->id, $startAt];
        $workers[] = ['race.php', 'fill', $order->id, $user->id, $startAt];
    }

    $outputs = runTogether($workers);

    foreach ($orders as $i => $order) {
        $order->refresh();
        [$cancel, $fill] = [$outputs[$i * 2], $outputs[$i * 2 + 1]];
        $executions = Execution::where('order_id', $order->id)->count();
        $releases = LedgerEntry::where('order_id', $order->id)->where('type', 'RELEASE')->where('bucket', 'RESERVED')->count();

        if ($order->status === OrderStatus::Filled) {
            expect([$cancel, $fill])->toBe(['CANCEL_LOST', 'FILLED'])
                ->and($executions)->toBe(1)
                ->and($releases)->toBe(1);
        } else {
            expect($order->status)->toBe(OrderStatus::Cancelled)
                ->and([$cancel, $fill])->toBe(['CANCELLED', 'ALREADY_FINAL'])
                ->and($executions)->toBe(0)
                ->and($releases)->toBe(1);
        }
    }

    expect((string) wallet($user)->reserved_balance)->toBe('0');
    assertLedgerConsistent();
});
