<?php

declare(strict_types=1);

use App\Support\Decimal;
use App\Support\RandomWalk;

it('moves at most 10 basis points and respects the price precision', function (int $precision, string $start) {
    $price = Decimal::of($start);

    foreach (range(1, 500) as $i) {
        $next = RandomWalk::step($price, $precision);
        $limit = $price->times('0.001')->plus(Decimal::of('1')->dividedBy(10 ** $precision, $precision));

        expect($next->fitsScale($precision))->toBeTrue()
            ->and($next->minus($price)->compare($limit) <= 0)->toBeTrue()
            ->and($price->minus($next)->compare($limit) <= 0)->toBeTrue();

        $price = $next;
    }
})->with([
    'two decimals' => [2, '65000.00'],
    'five decimals' => [5, '1.08000'],
]);

it('never reaches zero', function () {
    $price = Decimal::of('0.01');

    foreach (range(1, 500) as $i) {
        $price = RandomWalk::step($price, 2);
        expect($price->isPositive())->toBeTrue();
    }
});
