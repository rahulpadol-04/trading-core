<?php

declare(strict_types=1);

namespace App\Support;

final class RandomWalk
{
    public static function step(Decimal $price, int $pricePrecision): Decimal
    {
        $basisPoints = random_int(-10, 10);
        $next = $price->times(Decimal::of('1')->plus(Decimal::of($basisPoints)->dividedBy('10000', 4)))->round($pricePrecision);

        $minimumStep = Decimal::of('1')->dividedBy(10 ** $pricePrecision, $pricePrecision);

        return $next->isLessThan($minimumStep) ? $minimumStep : $next;
    }
}
