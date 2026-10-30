<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = trader();
    $this->asset = tradableAsset();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
});

it('returns only the trader own orders, newest first', function () {
    $mine = Order::factory()->count(3)->for($this->user)->for($this->asset)->create();
    Order::factory()->count(2)->for(trader())->for($this->asset)->create();
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $mine->last()->uuid)
        ->assertJsonPath('data.0.asset', $this->asset->symbol);
});

it('filters by status and by asset', function () {
    Order::factory()->for($this->user)->for($this->asset)->create();
    Order::factory()->for($this->user)->for($this->asset)->status(OrderStatus::Filled)->create();
    $other = tradableAsset();
    Order::factory()->for($this->user)->for($other)->create();
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/orders?status=FILLED')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'FILLED');
    $this->getJson("/api/v1/orders?asset={$other->symbol}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset', $other->symbol);
    $this->getJson("/api/v1/orders?status=PENDING&asset={$this->asset->symbol}")->assertJsonCount(1, 'data');
});

it('validates filters', function (string $query, string $field) {
    Sanctum::actingAs($this->user);

    $this->getJson("/api/v1/orders?{$query}")
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown status' => ['status=DONE', 'status'],
    'unknown asset' => ['asset=NOPE', 'asset'],
    'page size too big' => ['per_page=101', 'per_page'],
]);

it('paginates with a cursor without duplicates or gaps', function () {
    Order::factory()->count(30)->for($this->user)->for($this->asset)->create();
    Sanctum::actingAs($this->user);

    $seen = [];
    $url = '/api/v1/orders?per_page=10';

    foreach (range(1, 3) as $page) {
        $response = $this->getJson($url)->assertOk()->assertJsonCount(10, 'data');
        $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        $url = '/api/v1/orders?per_page=10&cursor='.$response->json('meta.next_cursor');
    }

    expect($response->json('meta.next_cursor'))->toBeNull()
        ->and(array_unique($seen))->toHaveCount(30)
        ->and($seen)->toBe(Order::query()->orderByDesc('id')->pluck('uuid')->all());
});
