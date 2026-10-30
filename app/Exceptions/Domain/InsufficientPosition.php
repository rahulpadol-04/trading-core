<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Support\Decimal;

final class InsufficientPosition extends DomainException
{
    public function __construct(private readonly Decimal $required, private readonly Decimal $available)
    {
        parent::__construct('Available quantity is too low for this order.');
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_POSITION';
    }

    public function details(): array
    {
        return ['required' => (string) $this->required, 'available' => (string) $this->available];
    }
}
