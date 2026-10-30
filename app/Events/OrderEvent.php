<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Context;

/**
 * Carries ids and plain snapshots, never models, so a queued listener records the
 * state at the moment of the change rather than whatever the row holds later.
 */
abstract class OrderEvent implements AuditableOrderEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly ?string $correlationId;

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @param  array<string, string|null>  $context
     */
    public function __construct(
        public readonly int $id,
        public readonly array $before,
        public readonly array $after,
        public readonly ?int $actor = null,
        public readonly ?string $ip = null,
        public readonly array $context = [],
    ) {
        $correlationId = Context::get('correlation_id');
        $this->correlationId = is_string($correlationId) ? $correlationId : null;
    }

    public function orderId(): int
    {
        return $this->id;
    }

    public function actorId(): ?int
    {
        return $this->actor;
    }

    public function ipAddress(): ?string
    {
        return $this->ip;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function auditContext(): array
    {
        return ['before' => $this->before, 'after' => $this->after, ...$this->context];
    }
}
