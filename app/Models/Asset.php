<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'name', 'price_precision', 'quantity_precision', 'is_tradable'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price_precision' => 'integer',
            'quantity_precision' => 'integer',
            'is_tradable' => 'boolean',
        ];
    }

    /** @return HasMany<MarketTick, $this> */
    public function ticks(): HasMany
    {
        return $this->hasMany(MarketTick::class);
    }
}
