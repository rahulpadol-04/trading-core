<?php

declare(strict_types=1);

use App\Jobs\ProcessOrderJob;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Ledger;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    $this->user = trader('1000');
    $this->asset = tradableAsset();
    $this->payload = [
        'asset' => $this->asset->symbol,
        'side' => 'BUY',
        'type' => 'LIMIT',
        'quantity' => '2',
        'limit_price' => '100',
    ];
});

function postOrder(array $payload, ?string $key = 'key-00000001'): TestResponse
{
    return test()->postJson('/api/v1/orders', $payload, $key === null ? [] : ['Idempotency-Key' => $key]);
}

it('requires authentication', function () {
    postOrder($this->payload)
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']]);
});

it('reserves quantity x limit_price and returns 202', function () {
    Sanctum::actingAs($this->user);

    postOrder($this->payload)
        ->assertStatus(202)
        ->assertHeader('Idempotency-Replayed', 'false')
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.asset', $this->asset->symbol)
        ->assertJsonPath('data.quantity', '2')
        ->assertJsonPath('data.limit_price', '100')
        ->assertJsonPath('data.reserved_amount', '200');

    expect((string) wallet($this->user)->available_balance)->toBe('800')
        ->and((string) wallet($this->user)->reserved_balance)->toBe('200');

    Queue::assertPushedOn('orders', ProcessOrderJob::class);
    assertLedgerConsistent();
});

it('gives LIMIT orders a default expiry', function () {
    Sanctum::actingAs($this->user);

    postOrder($this->payload)->assertStatus(202);

    expect(Order::firstOrFail()->expires_at->diffInMinutes(CarbonImmutable::now()->addDay(), true))->toBeLessThan(1.0);
});

it('replays the original response for a repeated key and payload', function () {
    Sanctum::actingAs($this->user);

    $first = postOrder($this->payload)->assertStatus(202);
    $second = postOrder([...$this->payload, 'quantity' => '2.000'])
        ->assertStatus(202)
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and(Order::count())->toBe(1)
        ->and((string) wallet($this->user)->reserved_balance)->toBe('200');

    Queue::assertPushed(ProcessOrderJob::class, 1);
});

it('rejects a repeated key with a different payload', function () {
    Sanctum::actingAs($this->user);

    postOrder($this->payload)->assertStatus(202);

    postOrder([...$this->payload, 'quantity' => '3'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    expect(Order::count())->toBe(1);
});

it('scopes idempotency keys per user', function () {
    Sanctum::actingAs($this->user);
    postOrder($this->payload)->assertStatus(202);

    Sanctum::actingAs(trader('1000'));
    postOrder($this->payload)->assertStatus(202)->assertHeader('Idempotency-Replayed', 'false');

    expect(Order::count())->toBe(2);
});

it('validates input', function (array $override, string $field) {
    Sanctum::actingAs($this->user);

    postOrder([...$this->payload, ...$override])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'limit price required for LIMIT' => [['limit_price' => null], 'limit_price'],
    'limit price forbidden for MARKET' => [['type' => 'MARKET'], 'limit_price'],
    'expiry forbidden for MARKET' => [['type' => 'MARKET', 'limit_price' => null, 'expires_at' => '2099-01-01T00:00:00Z'], 'expires_at'],
    'expiry in the past' => [['expires_at' => '2000-01-01T00:00:00Z'], 'expires_at'],
    'quantity must be a string' => [['quantity' => 2], 'quantity'],
    'limit price must be a string' => [['limit_price' => 100.5], 'limit_price'],
    'negative quantity' => [['quantity' => '-1'], 'quantity'],
    'zero quantity' => [['quantity' => '0'], 'quantity'],
    'quantity precision' => [['quantity' => '1.12345'], 'quantity'],
    'price precision' => [['limit_price' => '100.123'], 'limit_price'],
    'zero price' => [['limit_price' => '0.00'], 'limit_price'],
    'unknown asset' => [['asset' => 'NOPE'], 'asset'],
    'bad side' => [['side' => 'HOLD'], 'side'],
    'bad type' => [['type' => 'STOP'], 'type'],
]);

it('requires the Idempotency-Key header', function () {
    Sanctum::actingAs($this->user);

    postOrder($this->payload, key: null)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['idempotency_key']]]);
});

it('rejects a malformed Idempotency-Key', function () {
    Sanctum::actingAs($this->user);

    postOrder($this->payload, key: 'short')
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['idempotency_key']]]);
});

it('rejects untradable assets', function () {
    Sanctum::actingAs($this->user);
    $this->asset->update(['is_tradable' => false]);

    postOrder($this->payload)
        ->assertStatus(422)
        ->assertJsonPath('error.details.asset.0', 'This asset is not tradable.');
});

it('rejects insufficient funds, changes nothing and leaves the key reusable', function () {
    Sanctum::actingAs($this->user);

    postOrder([...$this->payload, 'quantity' => '11'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS')
        ->assertJsonPath('error.details.required', '1100')
        ->assertJsonPath('error.details.available', '1000');

    expect(Order::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0)
        ->and(LedgerEntry::where('type', 'RESERVE')->count())->toBe(0);
    Queue::assertNothingPushed();

    DB::transaction(fn () => app(Ledger::class)->deposit(wallet($this->user)->lockFresh(), Decimal::of('100')));

    postOrder([...$this->payload, 'quantity' => '11'])->assertStatus(202);
    assertLedgerConsistent();
});

it('moves position quantity into reserve for SELL orders', function () {
    Sanctum::actingAs($this->user);
    $position = holding($this->user, $this->asset, '5');

    postOrder([...$this->payload, 'side' => 'SELL', 'quantity' => '3'])
        ->assertStatus(202)
        ->assertJsonPath('data.reserved_amount', '0');

    $position->refresh();
    expect((string) $position->quantity)->toBe('2')
        ->and((string) $position->reserved_quantity)->toBe('3')
        ->and((string) wallet($this->user)->reserved_balance)->toBe('0');
    assertLedgerConsistent();
});

it('rejects SELL orders larger than the holding', function () {
    Sanctum::actingAs($this->user);
    holding($this->user, $this->asset, '1');

    postOrder([...$this->payload, 'side' => 'SELL', 'quantity' => '1.5'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INSUFFICIENT_POSITION');

    expect(Order::count())->toBe(0);
});

it('rejects MARKET orders when there is no fresh price', function (?int $ageSeconds) {
    Sanctum::actingAs($this->user);
    holding($this->user, $this->asset, '1');

    if ($ageSeconds !== null) {
        setPrice($this->asset, '100', CarbonImmutable::now()->subSeconds($ageSeconds));
    }

    foreach (['BUY', 'SELL'] as $i => $side) {
        postOrder(['asset' => $this->asset->symbol, 'side' => $side, 'type' => 'MARKET', 'quantity' => '1'], "market-key-{$i}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PRICE_UNAVAILABLE');
    }
})->with(['no price at all' => [null], 'price older than 30 s' => [31]]);

it('reserves MARKET BUY at last price plus the buffer', function () {
    Sanctum::actingAs($this->user);
    setPrice($this->asset, '100.01');

    postOrder(['asset' => $this->asset->symbol, 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => '3'])
        ->assertStatus(202)
        ->assertJsonPath('data.reserved_amount', '315.0315')
        ->assertJsonPath('data.expires_at', null);

    assertLedgerConsistent();
});

it('rounds the MARKET BUY reservation up, never down', function () {
    Sanctum::actingAs($this->user);
    $asset = tradableAsset(pricePrecision: 4, quantityPrecision: 4);
    setPrice($asset, '1.0001');

    postOrder(['asset' => $asset->symbol, 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => '0.0001'])
        ->assertStatus(202)
        ->assertJsonPath('data.reserved_amount', '0.00010502');
});

it('rate limits order placement per user', function () {
    config(['trading.order_rate_per_minute' => 2]);
    Sanctum::actingAs($this->user);

    postOrder([...$this->payload, 'quantity' => '1'], 'rate-key-01')->assertStatus(202);
    postOrder([...$this->payload, 'quantity' => '1'], 'rate-key-02')->assertStatus(202);

    postOrder([...$this->payload, 'quantity' => '1'], 'rate-key-03')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');
});
