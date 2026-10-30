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
 * @property CarbonImmutable $recorded_at
 */
#[Fillable(['asset_id', 'price', 'recorded_at'])]
#[WithoutTimestamps]
class MarketTick extends Model
{
    protected function casts(): array
    {
        return [
            'price' => DecimalCast::class,
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
