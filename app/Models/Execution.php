<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Casts\DecimalCast;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Decimal $price
 * @property Decimal $quantity
 * @property Decimal $notional
 * @property Decimal $fee
 * @property CarbonImmutable $executed_at
 */
#[Fillable(['order_id', 'asset_id', 'price', 'quantity', 'notional', 'fee', 'executed_at'])]
#[WithoutTimestamps]
class Execution extends Model
{
    protected function casts(): array
    {
        return [
            'price' => DecimalCast::class,
            'quantity' => DecimalCast::class,
            'notional' => DecimalCast::class,
            'fee' => DecimalCast::class,
            'executed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
