<?php

declare(strict_types=1);

use App\Support\Decimal;
use Brick\Math\Exception\RoundingNecessaryException;

it('adds without float drift', function () {
    expect((string) Decimal::of('0.1')->plus('0.2'))->toBe('0.3');
});

it('multiplies exactly', function () {
    expect((string) Decimal::of('123.45')->times('0.001'))->toBe('0.12345');
});

it('rounds reservations up, never down', function () {
    expect((string) Decimal::of('10.000000001')->roundUp(8))->toBe('10.00000001');
});

it('refuses to store more than 8 decimals silently', function () {
    Decimal::of('1.123456789')->toStorage();
})->throws(RoundingNecessaryException::class);

it('stores values with trailing zeros beyond scale 8', function () {
    expect(Decimal::of('0.0100000000')->times('65000.12')->toStorage())->toBe('650.00120000');
});

it('rejects non-decimal strings', function (string $input) {
    Decimal::of($input);
})->with(['1e5', '1.', '.5', 'abc', '', '1,5'])->throws(InvalidArgumentException::class);

it('checks scale for precision validation', function () {
    expect(Decimal::of('1.2300')->fitsScale(2))->toBeTrue()
        ->and(Decimal::of('1.234')->fitsScale(2))->toBeFalse();
});

it('compares values', function () {
    expect(Decimal::of('2')->isGreaterThan('1.99999999'))->toBeTrue()
        ->and(Decimal::of('5.0')->equals('5'))->toBeTrue()
        ->and(Decimal::of('-0.00000001')->isNegative())->toBeTrue();
});

it('divides with an explicit scale and banker rounding', function () {
    expect((string) Decimal::of('1')->dividedBy('3', 4))->toBe('0.3333')
        ->and((string) Decimal::of('0.125')->round(2))->toBe('0.12');
});

it('serialises to JSON as a normalised string', function () {
    expect(json_encode(['q' => Decimal::of('1.50000000')]))->toBe('{"q":"1.5"}');
});
