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
 * @property Decimal $quantity
 * @property Decimal $balance_after
 */
#[Fillable(['position_id', 'order_id', 'type', 'bucket', 'quantity', 'balance_after'])]
class PositionEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'bucket' => LedgerBucket::class,
            'quantity' => DecimalCast::class,
            'balance_after' => DecimalCast::class,
        ];
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
