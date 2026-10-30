<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Exceptions\Domain\InsufficientFunds;
use App\Exceptions\Domain\InsufficientPosition;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Position;
use App\Models\PositionEntry;
use App\Models\Wallet;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Ledger
{
    public function deposit(Wallet $wallet, Decimal $amount): void
    {
        $this->moveCash($wallet, LedgerBucket::Available, $amount, LedgerEntryType::Deposit, null);
    }

    public function reserve(Wallet $wallet, Decimal $amount, Order $order): void
    {
        $this->moveCash($wallet, LedgerBucket::Available, $amount->negate(), LedgerEntryType::Reserve, $order);
        $this->moveCash($wallet, LedgerBucket::Reserved, $amount, LedgerEntryType::Reserve, $order);
    }

    public function release(Wallet $wallet, Decimal $amount, Order $order): void
    {
        if ($amount->isZero()) {
            return;
        }

        $this->moveCash($wallet, LedgerBucket::Reserved, $amount->negate(), LedgerEntryType::Release, $order);
        $this->moveCash($wallet, LedgerBucket::Available, $amount, LedgerEntryType::Release, $order);
    }

    public function spendReserved(Wallet $wallet, Decimal $amount, Order $order): void
    {
        $this->moveCash($wallet, LedgerBucket::Reserved, $amount->negate(), LedgerEntryType::Trade, $order);
    }

    public function creditProceeds(Wallet $wallet, Decimal $amount, Order $order): void
    {
        $this->moveCash($wallet, LedgerBucket::Available, $amount, LedgerEntryType::Trade, $order);
    }

    public function lockPosition(int $userId, int $assetId): Position
    {
        $this->assertInTransaction();

        Position::query()->insertOrIgnore([
            'user_id' => $userId,
            'asset_id' => $assetId,
            'quantity' => '0',
            'reserved_quantity' => '0',
            'average_cost' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Position::query()
            ->where('user_id', $userId)
            ->where('asset_id', $assetId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function depositQuantity(Position $position, Decimal $quantity, Decimal $cost): void
    {
        $this->updateAverageCost($position, $quantity, $cost);
        $this->moveQuantity($position, LedgerBucket::Available, $quantity, LedgerEntryType::Deposit, null);
    }

    public function reserveQuantity(Position $position, Decimal $quantity, Order $order): void
    {
        $this->moveQuantity($position, LedgerBucket::Available, $quantity->negate(), LedgerEntryType::Reserve, $order);
        $this->moveQuantity($position, LedgerBucket::Reserved, $quantity, LedgerEntryType::Reserve, $order);
    }

    public function releaseQuantity(Position $position, Decimal $quantity, Order $order): void
    {
        $this->moveQuantity($position, LedgerBucket::Reserved, $quantity->negate(), LedgerEntryType::Release, $order);
        $this->moveQuantity($position, LedgerBucket::Available, $quantity, LedgerEntryType::Release, $order);
    }

    public function deliverReserved(Position $position, Decimal $quantity, Order $order): void
    {
        $this->moveQuantity($position, LedgerBucket::Reserved, $quantity->negate(), LedgerEntryType::Trade, $order);

        if ($position->quantity->isZero() && $position->reserved_quantity->isZero()) {
            $position->average_cost = Decimal::zero();
            $position->save();
        }
    }

    public function receiveFilled(Position $position, Decimal $quantity, Decimal $price, Order $order): void
    {
        $this->updateAverageCost($position, $quantity, $price);
        $this->moveQuantity($position, LedgerBucket::Available, $quantity, LedgerEntryType::Trade, $order);
    }

    private function moveCash(Wallet $wallet, LedgerBucket $bucket, Decimal $delta, LedgerEntryType $type, ?Order $order): void
    {
        $this->assertInTransaction();

        $before = $bucket === LedgerBucket::Available ? $wallet->available_balance : $wallet->reserved_balance;
        $after = $before->plus($delta);

        if ($after->isNegative()) {
            throw new InsufficientFunds(required: $delta->negate(), available: $before);
        }

        if ($bucket === LedgerBucket::Available) {
            $wallet->available_balance = $after;
        } else {
            $wallet->reserved_balance = $after;
        }
        $wallet->save();

        LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'order_id' => $order?->id,
            'type' => $type,
            'bucket' => $bucket,
            'amount' => $delta,
            'balance_after' => $after,
        ]);
    }

    private function moveQuantity(Position $position, LedgerBucket $bucket, Decimal $delta, LedgerEntryType $type, ?Order $order): void
    {
        $this->assertInTransaction();

        $before = $bucket === LedgerBucket::Available ? $position->quantity : $position->reserved_quantity;
        $after = $before->plus($delta);

        if ($after->isNegative()) {
            throw new InsufficientPosition(required: $delta->negate(), available: $before);
        }

        if ($bucket === LedgerBucket::Available) {
            $position->quantity = $after;
        } else {
            $position->reserved_quantity = $after;
        }
        $position->save();

        PositionEntry::create([
            'position_id' => $position->id,
            'order_id' => $order?->id,
            'type' => $type,
            'bucket' => $bucket,
            'quantity' => $delta,
            'balance_after' => $after,
        ]);
    }

    /**
     * Weighted average cost, HALF_EVEN at scale 8. Informational only: it never moves money.
     */
    private function updateAverageCost(Position $position, Decimal $quantity, Decimal $price): void
    {
        $held = $position->quantity->plus($position->reserved_quantity);

        $position->average_cost = $held->times($position->average_cost)
            ->plus($quantity->times($price))
            ->dividedBy($held->plus($quantity), Decimal::STORAGE_SCALE);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Ledger operations must run inside a database transaction.');
        }
    }
}
