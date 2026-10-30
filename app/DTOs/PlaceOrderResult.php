<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaceOrderResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $orderId,
        public int $status,
        public array $body,
        public bool $replayed,
    ) {}
}
