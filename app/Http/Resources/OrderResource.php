<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'asset' => $this->whenLoaded('asset', fn (): string => $this->asset->symbol),
            'side' => $this->side->value,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'quantity' => (string) $this->quantity,
            'limit_price' => $this->limit_price?->normalized(),
            'reserved_amount' => (string) $this->reserved_amount,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'filled_at' => $this->filled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'executions' => ExecutionResource::collection($this->whenLoaded('executions')),
        ];
    }
}
