<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MatchDecision;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Support\Decimal;
use LogicException;

/**
 * Pure matching rules: no database, no Redis.
 */
final class MatchingRules
{
    /**
     * @param  Decimal|null  $freshPrice  latest price, or null when it is older than the max age
     */
    public function decide(OrderSide $side, OrderType $type, ?Decimal $limitPrice, ?Decimal $freshPrice): MatchDecision
    {
        if ($type === OrderType::Market) {
            return $freshPrice === null ? MatchDecision::Reject : MatchDecision::Fill;
        }

        if ($limitPrice === null) {
            throw new LogicException('LIMIT orders need a limit price.');
        }

        if ($freshPrice === null) {
            return MatchDecision::Wait;
        }

        $crossed = match ($side) {
            OrderSide::Buy => $freshPrice->isLessThanOrEqual($limitPrice),
            OrderSide::Sell => $freshPrice->isGreaterThanOrEqual($limitPrice),
        };

        return $crossed ? MatchDecision::Fill : MatchDecision::Wait;
    }
}
