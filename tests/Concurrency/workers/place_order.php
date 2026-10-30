<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrderAction;
use App\DTOs\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Exceptions\Domain\DomainException;
use App\Support\Decimal;

require __DIR__.'/bootstrap.php';

[, $userId, $assetId, $key, $startAt] = $argv;

waitForStart($startAt);

try {
    app(PlaceOrderAction::class)->execute(new PlaceOrderData(
        userId: (int) $userId,
        assetId: (int) $assetId,
        side: OrderSide::Buy,
        type: OrderType::Limit,
        quantity: Decimal::of('1'),
        limitPrice: Decimal::of('100'),
        expiresAt: null,
        idempotencyKey: $key,
        requestIp: null,
    ));

    echo json_encode(['result' => 'accepted']);
} catch (DomainException $e) {
    echo json_encode(['result' => 'rejected', 'code' => $e->errorCode()]);
}
