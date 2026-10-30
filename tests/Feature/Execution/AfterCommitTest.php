<?php

declare(strict_types=1);

use App\Actions\Orders\SettleOrderAction;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderFilled;
use App\Jobs\ProcessOrderJob;
use App\Models\AuditLog;
use App\Models\Execution;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('emits no event and writes no audit row when settlement rolls back', function () {
    Queue::fake([ProcessOrderJob::class]);
    $user = trader('1000');
    $asset = tradableAsset();
    setPrice($asset, '97');
    $order = placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '2', '100');
    $auditRows = AuditLog::count();

    $fired = 0;
    Event::listen(OrderFilled::class, function () use (&$fired) {
        $fired++;
    });

    try {
        DB::transaction(function () use ($order) {
            app(SettleOrderAction::class)->execute($order->id);

            throw new RuntimeException('crash after settlement, before commit');
        });
    } catch (RuntimeException) {
    }

    expect($fired)->toBe(0)
        ->and(AuditLog::count())->toBe($auditRows)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Execution::count())->toBe(0);

    assertLedgerConsistent();
});

it('queues execution only when placement commits', function (bool $commit, int $expectedRuns) {
    $user = trader('1000');
    $asset = tradableAsset();
    $runs = 0;
    Queue::before(function (JobProcessing $event) use (&$runs) {
        if ($event->job->resolveName() === ProcessOrderJob::class) {
            $runs++;
        }
    });

    try {
        DB::transaction(function () use ($user, $asset, $commit) {
            placeOrder($user, $asset, OrderSide::Buy, OrderType::Limit, '1', '100');

            if (! $commit) {
                throw new RuntimeException('rolled back');
            }
        });
    } catch (RuntimeException) {
    }

    expect($runs)->toBe($expectedRuns);
})->with([
    'committed' => [true, 1],
    'rolled back' => [false, 0],
]);
