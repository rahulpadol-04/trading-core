<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\MarketTick;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\TraderSeeder;

it('seeds assets, traders with working tokens, ticks and real fills', function () {
    $this->seed();

    expect(Asset::count())->toBe(5)
        ->and(User::count())->toBe(3)
        ->and(MarketTick::count())->toBeGreaterThan(5 * 24 * 60)
        ->and(Order::where('status', OrderStatus::Filled)->count())->toBe(6);

    foreach (TraderSeeder::TRADERS as [, $email, $token]) {
        $this->withToken($token)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.currency', 'USD');
        $this->app['auth']->forgetGuards();
    }

    $this->withToken('not-a-real-token')->getJson('/api/v1/wallet')->assertUnauthorized();

    assertLedgerConsistent();
});
