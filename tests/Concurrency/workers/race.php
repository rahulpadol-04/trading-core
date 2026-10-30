<?php

declare(strict_types=1);

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\SettleOrderAction;
use App\Exceptions\Domain\OrderNotCancellable;
use App\Models\Order;

require __DIR__.'/bootstrap.php';

[, $mode, $orderId, $userId, $startAt] = $argv;

waitForStart($startAt);

try {
    if ($mode === 'cancel') {
        app(CancelOrderAction::class)->execute(Order::findOrFail((int) $orderId), CancelOrderAction::REASON_USER, (int) $userId, null);
        echo 'CANCELLED';
    } else {
        echo app(SettleOrderAction::class)->execute((int) $orderId)->value;
    }
} catch (OrderNotCancellable) {
    echo 'CANCEL_LOST';
}
