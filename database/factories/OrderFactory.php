<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Orders made here hold no reservation. Use them for read-only tests only;
 * money tests place orders through PlaceOrderAction.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'asset_id' => Asset::factory(),
            'side' => OrderSide::Buy,
            'type' => OrderType::Limit,
            'status' => OrderStatus::Pending,
            'quantity' => '1',
            'limit_price' => '100',
            'reserved_amount' => '0',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function market(): static
    {
        return $this->state(['type' => OrderType::Market, 'limit_price' => null]);
    }

    public function sell(): static
    {
        return $this->state(['side' => OrderSide::Sell]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
