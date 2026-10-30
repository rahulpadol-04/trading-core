<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\DTOs\PlaceOrderData;
use App\DTOs\PlaceOrderResult;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderPlaced;
use App\Exceptions\Domain\IdempotencyKeyReused;
use App\Exceptions\Domain\PriceUnavailable;
use App\Http\Resources\OrderResource;
use App\Jobs\ProcessOrderJob;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Ledger;
use App\Services\PriceStore;
use App\Support\Decimal;
use App\Support\OrderSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PlaceOrderAction
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly PriceStore $prices,
    ) {}

    public function execute(PlaceOrderData $data): PlaceOrderResult
    {
        $hash = $data->requestHash();

        $replay = $this->replay($data, $hash);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return DB::transaction(fn (): PlaceOrderResult => $this->placeLocked($data, $hash), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // A twin with the same key committed between our checks. If its replay record
            // is gone the key belongs to an old order, and keys are single-use forever.
            return $this->replay($data, $hash) ?? throw new IdempotencyKeyReused;
        }
    }

    private function placeLocked(PlaceOrderData $data, string $hash): PlaceOrderResult
    {
        $wallet = null;
        $position = null;

        if ($data->side === OrderSide::Buy) {
            $wallet = Wallet::query()
                ->where('user_id', $data->userId)
                ->where('currency', config('trading.wallet_currency'))
                ->lockForUpdate()
                ->firstOrFail();
        } else {
            $position = $this->ledger->lockPosition($data->userId, $data->assetId);
        }

        // A twin request may have committed while this one waited for the lock.
        $replay = $this->replay($data, $hash);
        if ($replay !== null) {
            return $replay;
        }

        $order = Order::create([
            'user_id' => $data->userId,
            'asset_id' => $data->assetId,
            'side' => $data->side,
            'type' => $data->type,
            'status' => OrderStatus::Pending,
            'quantity' => $data->quantity,
            'limit_price' => $data->limitPrice,
            'reserved_amount' => $this->cashToReserve($data),
            'idempotency_key' => $data->idempotencyKey,
            'request_ip' => $data->requestIp,
            'expires_at' => $data->type === OrderType::Limit
                ? ($data->expiresAt ?? CarbonImmutable::now()->addMinutes(config('trading.default_limit_ttl_minutes')))
                : null,
        ]);

        if ($wallet !== null) {
            $this->ledger->reserve($wallet, $order->reserved_amount, $order);
        } elseif ($position !== null) {
            $this->ledger->reserveQuantity($position, $order->quantity, $order);
        }

        $body = (new OrderResource($order->refresh()->load('asset')))->response()->getData(true);

        IdempotencyKey::create([
            'user_id' => $data->userId,
            'key' => $data->idempotencyKey,
            'request_hash' => $hash,
            'order_id' => $order->id,
            'response_status' => 202,
            'response_body' => $body,
        ]);

        ProcessOrderJob::dispatch($order->id)->afterCommit();

        OrderPlaced::dispatch($order->id, [], OrderSnapshot::of($order), $data->userId, $data->requestIp);

        return new PlaceOrderResult($order->id, 202, $body, replayed: false);
    }

    /**
     * LIMIT BUY holds quantity x limit, exact at scale 8. MARKET BUY holds quantity x the last
     * fresh price x (1 + buffer), rounded up so the hold always covers the fill.
     */
    private function cashToReserve(PlaceOrderData $data): Decimal
    {
        if ($data->type === OrderType::Limit) {
            $limitPrice = $data->limitPrice ?? throw new LogicException('LIMIT orders need a limit price.');

            return $data->side === OrderSide::Buy ? $data->quantity->times($limitPrice) : Decimal::zero();
        }

        $maxAge = config('trading.price_max_age_seconds');
        $price = $this->prices->fresh($data->assetId, $maxAge) ?? throw new PriceUnavailable($maxAge);

        if ($data->side === OrderSide::Sell) {
            return Decimal::zero();
        }

        $buffer = Decimal::of('1')->plus((string) config('trading.market_buy_buffer'));

        return $data->quantity->times($price)->times($buffer)->roundUp(Decimal::STORAGE_SCALE);
    }

    private function replay(PlaceOrderData $data, string $hash): ?PlaceOrderResult
    {
        $stored = IdempotencyKey::query()
            ->where('user_id', $data->userId)
            ->where('key', $data->idempotencyKey)
            ->first();

        if ($stored === null) {
            return null;
        }

        if (! hash_equals($stored->request_hash, $hash)) {
            throw new IdempotencyKeyReused;
        }

        return new PlaceOrderResult($stored->order_id, $stored->response_status, $stored->response_body, replayed: true);
    }
}
