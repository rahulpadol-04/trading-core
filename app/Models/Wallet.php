<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LocksForUpdate;
use App\Support\Casts\DecimalCast;
use App\Support\Decimal;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Decimal $available_balance
 * @property Decimal $reserved_balance
 */
#[Fillable(['user_id', 'currency'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory, LocksForUpdate;

    protected function casts(): array
    {
        return [
            'available_balance' => DecimalCast::class,
            'reserved_balance' => DecimalCast::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
