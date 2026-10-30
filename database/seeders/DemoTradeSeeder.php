<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Orders\PlaceOrderAction;
use App\Actions\Orders\SettleOrderAction;
use App\DTOs\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Seeder;

class DemoTradeSeeder extends Seeder
{
    /**
     * A few real fills through the actions, so the dashboard has volume and the
     * ledger stays consistent. The queued ProcessOrderJob later finds them FILLED.
     */
    public function run(PlaceOrderAction $place, SettleOrderAction $settle): void
    {
        $plan = [
            ['alice@example.com', 'BTC-USD', '0.01'],
            ['bob@example.com', 'ETH-USD', '1.5'],
            ['carol@example.com', 'SOL-USD', '10'],
            ['alice@example.com', 'AAPL', '5'],
            ['bob@example.com', 'EUR-USD', '1000'],
            ['carol@example.com', 'BTC-USD', '0.02'],
        ];

        foreach ($plan as $i => [$email, $symbol, $quantity]) {
            $result = $place->execute(new PlaceOrderData(
                userId: User::query()->where('email', $email)->value('id'),
                assetId: Asset::query()->where('symbol', $symbol)->value('id'),
                side: OrderSide::Buy,
                type: OrderType::Market,
                quantity: Decimal::of($quantity),
                limitPrice: null,
                expiresAt: null,
                idempotencyKey: "seed-demo-{$i}",
                requestIp: null,
            ));

            $settle->execute($result->orderId);
        }
    }
}
