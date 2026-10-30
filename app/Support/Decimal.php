<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final class Decimal implements JsonSerializable, Stringable
{
    public const int STORAGE_SCALE = 8;

    private function __construct(private readonly BigDecimal $value) {}

    public static function of(self|string|int $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal string: {$value}");
        }

        return new self(BigDecimal::of($value));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function plus(self|string|int $other): self
    {
        return new self($this->value->plus(self::of($other)->value));
    }

    public function minus(self|string|int $other): self
    {
        return new self($this->value->minus(self::of($other)->value));
    }

    public function times(self|string|int $other): self
    {
        return new self($this->value->multipliedBy(self::of($other)->value));
    }

    public function dividedBy(self|string|int $other, int $scale, RoundingMode $mode = RoundingMode::HalfEven): self
    {
        return new self($this->value->dividedBy(self::of($other)->value, $scale, $mode));
    }

    public function roundUp(int $scale = self::STORAGE_SCALE): self
    {
        return new self($this->value->toScale($scale, RoundingMode::Up));
    }

    public function round(int $scale, RoundingMode $mode = RoundingMode::HalfEven): self
    {
        return new self($this->value->toScale($scale, $mode));
    }

    public function compare(self|string|int $other): int
    {
        return $this->value->compareTo(self::of($other)->value);
    }

    public function isLessThan(self|string|int $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isLessThanOrEqual(self|string|int $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isGreaterThan(self|string|int $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isGreaterThanOrEqual(self|string|int $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function equals(self|string|int $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function negate(): self
    {
        return new self($this->value->negated());
    }

    /**
     * Number of digits after the point once trailing zeros are dropped.
     */
    public function significantScale(): int
    {
        return $this->value->strippedOfTrailingZeros()->getScale();
    }

    public function fitsScale(int $scale): bool
    {
        return $this->significantScale() <= $scale;
    }

    /**
     * Database representation. Throws instead of silently dropping digits,
     * so rounding is always an explicit decision of the caller.
     */
    public function toStorage(): string
    {
        return (string) $this->value->toScale(self::STORAGE_SCALE, RoundingMode::Unnecessary);
    }

    /**
     * Canonical form for hashing and JSON: "1.5", never "1.50000000".
     */
    public function normalized(): string
    {
        return (string) $this->value->strippedOfTrailingZeros();
    }

    public function __toString(): string
    {
        return $this->normalized();
    }

    public function jsonSerialize(): string
    {
        return $this->normalized();
    }
}
