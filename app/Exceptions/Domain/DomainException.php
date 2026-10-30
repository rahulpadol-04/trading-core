<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * An expected business outcome, rendered as a 4xx with a stable error code.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function errorCode(): string;

    public function status(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [];
    }
}
