<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Support\Decimal;

final class InsufficientFunds extends DomainException
{
    public function __construct(private readonly Decimal $required, private readonly Decimal $available)
    {
        parent::__construct('Available balance is too low for this order.');
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_FUNDS';
    }

    public function details(): array
    {
        return ['required' => (string) $this->required, 'available' => (string) $this->available];
    }
}
