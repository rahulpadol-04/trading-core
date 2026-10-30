<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wallet
 */
final class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency,
            'available_balance' => (string) $this->available_balance,
            'reserved_balance' => (string) $this->reserved_balance,
            'total_balance' => (string) $this->available_balance->plus($this->reserved_balance),
            'positions' => PositionResource::collection($this->whenLoaded('positions')),
        ];
    }
}
