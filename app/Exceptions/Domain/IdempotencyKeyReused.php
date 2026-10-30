<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class IdempotencyKeyReused extends DomainException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with a different request.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSED';
    }
}
