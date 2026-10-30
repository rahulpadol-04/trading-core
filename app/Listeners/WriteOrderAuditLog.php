<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AuditableOrderEvent;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

final class WriteOrderAuditLog implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(AuditableOrderEvent $event): void
    {
        AuditLog::create([
            'actor_id' => $event->actorId(),
            'actor_type' => $event->actorId() === null ? 'SYSTEM' : 'USER',
            'action' => $event->auditAction(),
            'subject_type' => Order::class,
            'subject_id' => $event->orderId(),
            'context' => $event->auditContext(),
            'ip_address' => $event->ipAddress(),
            'correlation_id' => $event->correlationId(),
        ]);
    }
}
