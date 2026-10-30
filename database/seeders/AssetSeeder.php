<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    /**
     * symbol, name, price precision, quantity precision, starting price
     *
     * @var list<array{string, string, int, int, string}>
     */
    public const array ASSETS = [
        ['BTC-USD', 'Bitcoin', 2, 6, '65000.00'],
        ['ETH-USD', 'Ether', 2, 5, '3200.00'],
        ['SOL-USD', 'Solana', 3, 4, '150.000'],
        ['AAPL', 'Apple Inc.', 2, 2, '230.00'],
        ['EUR-USD', 'Euro / Dollar', 5, 2, '1.08000'],
    ];

    public function run(): void
    {
        foreach (self::ASSETS as [$symbol, $name, $pricePrecision, $quantityPrecision]) {
            Asset::query()->updateOrCreate(['symbol' => $symbol], [
                'name' => $name,
                'price_precision' => $pricePrecision,
                'quantity_precision' => $quantityPrecision,
                'is_tradable' => true,
            ]);
        }
    }
}
