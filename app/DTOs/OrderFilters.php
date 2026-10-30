<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\OrderStatus;

final readonly class OrderFilters
{
    public function __construct(
        public ?OrderStatus $status,
        public ?int $assetId,
        public int $perPage,
    ) {}
}
