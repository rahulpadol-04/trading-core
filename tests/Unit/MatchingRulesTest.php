<?php

declare(strict_types=1);

use App\Enums\MatchDecision;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Services\MatchingRules;
use App\Support\Decimal;

it('applies the matching rules', function (OrderSide $side, OrderType $type, ?string $limit, ?string $price, MatchDecision $expected) {
    $decision = (new MatchingRules)->decide(
        $side,
        $type,
        $limit === null ? null : Decimal::of($limit),
        $price === null ? null : Decimal::of($price),
    );

    expect($decision)->toBe($expected);
})->with([
    'buy limit at limit fills' => [OrderSide::Buy, OrderType::Limit, '100', '100', MatchDecision::Fill],
    'buy limit below limit fills' => [OrderSide::Buy, OrderType::Limit, '100', '99.99', MatchDecision::Fill],
    'buy limit above limit waits' => [OrderSide::Buy, OrderType::Limit, '100', '100.01', MatchDecision::Wait],
    'sell limit at limit fills' => [OrderSide::Sell, OrderType::Limit, '100', '100', MatchDecision::Fill],
    'sell limit above limit fills' => [OrderSide::Sell, OrderType::Limit, '100', '100.01', MatchDecision::Fill],
    'sell limit below limit waits' => [OrderSide::Sell, OrderType::Limit, '100', '99.99', MatchDecision::Wait],
    'limit without fresh price waits' => [OrderSide::Buy, OrderType::Limit, '100', null, MatchDecision::Wait],
    'market buy with fresh price fills' => [OrderSide::Buy, OrderType::Market, null, '100', MatchDecision::Fill],
    'market sell with fresh price fills' => [OrderSide::Sell, OrderType::Market, null, '100', MatchDecision::Fill],
    'market without fresh price rejects' => [OrderSide::Sell, OrderType::Market, null, null, MatchDecision::Reject],
]);

it('refuses a LIMIT order without a limit price', function () {
    (new MatchingRules)->decide(OrderSide::Buy, OrderType::Limit, null, Decimal::of('1'));
})->throws(LogicException::class);
