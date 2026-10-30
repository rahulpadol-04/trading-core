<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

final readonly class PlaceOrderData
{
    public function __construct(
        public int $userId,
        public int $assetId,
        public OrderSide $side,
        public OrderType $type,
        public Decimal $quantity,
        public ?Decimal $limitPrice,
        public ?CarbonImmutable $expiresAt,
        public string $idempotencyKey,
        public ?string $requestIp,
    ) {}

    /**
     * Fingerprint of what the client asked for, normalised so "1.50" and "1.5" match.
     */
    public function requestHash(): string
    {
        return hash('sha256', json_encode([
            'asset_id' => $this->assetId,
            'side' => $this->side->value,
            'type' => $this->type->value,
            'quantity' => $this->quantity->normalized(),
            'limit_price' => $this->limitPrice?->normalized(),
            'expires_at' => $this->expiresAt?->utc()->format('Y-m-d\TH:i:s.uP'),
        ], JSON_THROW_ON_ERROR));
    }
}
