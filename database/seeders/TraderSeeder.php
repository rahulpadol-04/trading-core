<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Support\Decimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TraderSeeder extends Seeder
{
    /**
     * Local review tokens only. Sanctum stores sha256(token) and accepts a token
     * without the "id|" prefix, so these strings work as Bearer tokens as-is.
     *
     * @var list<array{string, string, string}>
     */
    public const array TRADERS = [
        ['Alice Trader', 'alice@example.com', 'alice-local-token-0000000000000000000000000001'],
        ['Bob Trader', 'bob@example.com', 'bob-local-token-00000000000000000000000000002'],
        ['Carol Trader', 'carol@example.com', 'carol-local-token-000000000000000000000000003'],
    ];

    /**
     * symbol => [quantity, average cost]
     *
     * @var array<string, array{string, string}>
     */
    public const array HOLDINGS = [
        'BTC-USD' => ['0.5', '60000'],
        'ETH-USD' => ['5', '3000'],
        'AAPL' => ['20', '210'],
    ];

    public function run(Ledger $ledger): void
    {
        foreach (self::TRADERS as [$name, $email, $plainToken]) {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => 'password']);

            $user->tokens()->create([
                'name' => 'seeded',
                'token' => hash('sha256', $plainToken),
                'abilities' => ['*'],
            ]);

            $wallet = Wallet::query()->create(['user_id' => $user->id, 'currency' => config('trading.wallet_currency')]);

            DB::transaction(function () use ($ledger, $wallet, $user): void {
                $ledger->deposit($wallet->lockFresh(), Decimal::of('100000'));

                foreach (self::HOLDINGS as $symbol => [$quantity, $cost]) {
                    $asset = Asset::query()->where('symbol', $symbol)->firstOrFail();
                    $position = $ledger->lockPosition($user->id, $asset->id);
                    $ledger->depositQuantity($position, Decimal::of($quantity), Decimal::of($cost));
                }
            });

            $this->command?->info("Token for {$email}: {$plainToken}");
        }
    }
}
