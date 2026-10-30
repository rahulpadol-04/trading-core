<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Models\Concerns\AppendOnly;
use App\Support\Casts\DecimalCast;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Decimal $amount
 * @property Decimal $balance_after
 */
#[Fillable(['wallet_id', 'order_id', 'type', 'bucket', 'amount', 'balance_after'])]
class LedgerEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'bucket' => LedgerBucket::class,
            'amount' => DecimalCast::class,
            'balance_after' => DecimalCast::class,
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
