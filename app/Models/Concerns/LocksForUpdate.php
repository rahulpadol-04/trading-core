<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait LocksForUpdate
{
    /**
     * Re-read this row with SELECT ... FOR UPDATE. Must run inside a transaction.
     */
    public function lockFresh(): static
    {
        return static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }
}
