<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Position;
use App\Services\PriceStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
final class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latest = app(PriceStore::class)->latest($this->asset_id);

        return [
            'asset' => $this->asset->symbol,
            'quantity' => (string) $this->quantity,
            'reserved_quantity' => (string) $this->reserved_quantity,
            'average_cost' => (string) $this->average_cost,
            'last_price' => $latest === null ? null : $latest['price']->normalized(),
        ];
    }
}
