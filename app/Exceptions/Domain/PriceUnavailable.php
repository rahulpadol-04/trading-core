<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class PriceUnavailable extends DomainException
{
    public function __construct(private readonly int $maxAgeSeconds)
    {
        parent::__construct('No market price is available for this asset right now.');
    }

    public function errorCode(): string
    {
        return 'PRICE_UNAVAILABLE';
    }

    public function details(): array
    {
        return ['max_age_seconds' => $this->maxAgeSeconds];
    }
}
