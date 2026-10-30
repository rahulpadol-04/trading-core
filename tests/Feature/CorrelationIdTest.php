<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('echoes a valid X-Correlation-ID and generates one otherwise', function (?string $incoming, bool $kept) {
    Sanctum::actingAs(trader());

    $id = $this->getJson('/api/v1/wallet', $incoming === null ? [] : ['X-Correlation-ID' => $incoming])
        ->assertOk()
        ->headers->get('X-Correlation-ID');

    expect(Str::isUuid($id))->toBeTrue()
        ->and($id === $incoming)->toBe($kept);
})->with([
    'valid uuid' => ['8f14e45f-ceea-4672-9b7a-1a2b3c4d5e6f', true],
    'not a uuid' => ['drop table orders', false],
    'missing' => [null, false],
]);

it('carries the request correlation id into the settlement job and the audit log', function () {
    $user = trader('1000');
    $asset = tradableAsset();
    setPrice($asset, '99');
    $correlationId = (string) Str::uuid();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/orders', [
        'asset' => $asset->symbol, 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100',
    ], ['Idempotency-Key' => 'correlated-0001', 'X-Correlation-ID' => $correlationId])->assertStatus(202);

    expect(AuditLog::query()->pluck('correlation_id', 'action')->all())->toEqual([
        'order.placed' => $correlationId,
        'order.filled' => $correlationId,
    ]);
});
