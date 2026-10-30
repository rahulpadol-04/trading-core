<?php

declare(strict_types=1);

namespace App\Events;

interface AuditableOrderEvent
{
    public function auditAction(): string;

    public function orderId(): int;

    public function actorId(): ?int;

    public function ipAddress(): ?string;

    public function correlationId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array;
}
