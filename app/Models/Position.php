<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LocksForUpdate;
use App\Support\Casts\DecimalCast;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Decimal $quantity
 * @property Decimal $reserved_quantity
 * @property Decimal $average_cost
 */
#[Fillable(['user_id', 'asset_id'])]
class Position extends Model
{
    use LocksForUpdate;

    protected function casts(): array
    {
        return [
            'quantity' => DecimalCast::class,
            'reserved_quantity' => DecimalCast::class,
            'average_cost' => DecimalCast::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return HasMany<PositionEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PositionEntry::class);
    }
}
