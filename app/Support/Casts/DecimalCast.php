<?php

declare(strict_types=1);

namespace App\Support\Casts;

use App\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<Decimal|null, Decimal|string|int|null>
 */
final class DecimalCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Decimal
    {
        return $value === null ? null : Decimal::of((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::of($value)->toStorage();
    }
}
