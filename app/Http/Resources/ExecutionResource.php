<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Execution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Execution
 */
final class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'price' => (string) $this->price,
            'quantity' => (string) $this->quantity,
            'notional' => (string) $this->notional,
            'fee' => (string) $this->fee,
            'executed_at' => $this->executed_at->toIso8601String(),
        ];
    }
}
