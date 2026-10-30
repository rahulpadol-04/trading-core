<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency' => config('trading.wallet_currency'),
        ];
    }

    /**
     * Fund through the ledger so SUM(entries) equals the balance from the first row.
     */
    public function funded(string $amount): static
    {
        return $this->afterCreating(function (Wallet $wallet) use ($amount): void {
            DB::transaction(fn () => app(Ledger::class)->deposit($wallet->lockFresh(), Decimal::of($amount)));
        });
    }
}
