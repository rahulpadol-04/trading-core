<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\LocksForUpdate;
use App\Support\Casts\DecimalCast;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Decimal $quantity
 * @property Decimal|null $limit_price
 * @property Decimal $reserved_amount
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $filled_at
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable([
    'user_id', 'asset_id', 'side', 'type', 'status', 'quantity', 'limit_price',
    'reserved_amount', 'idempotency_key', 'request_ip', 'expires_at',
])]
#[RouteKey('uuid')]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids, LocksForUpdate;

    /**
     * HasUuids fills the public "uuid" column; the primary key stays an integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'quantity' => DecimalCast::class,
            'limit_price' => DecimalCast::class,
            'reserved_amount' => DecimalCast::class,
            'expires_at' => 'immutable_datetime',
            'filled_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** @param  Builder<Order>  $query */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }
}
