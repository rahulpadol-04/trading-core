<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\OrderStatus;

final class OrderNotCancellable extends DomainException
{
    public function __construct(private readonly OrderStatus $current)
    {
        parent::__construct('Only PENDING orders can be cancelled.');
    }

    public function errorCode(): string
    {
        return 'ORDER_NOT_CANCELLABLE';
    }

    public function status(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['status' => $this->current->value];
    }
}
