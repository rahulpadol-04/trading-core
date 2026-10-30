<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Orders\FailOrderAction;
use App\Actions\Orders\SettleOrderAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('orders');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 60];
    }

    public function handle(SettleOrderAction $settle): void
    {
        $settle->execute($this->orderId);
    }

    public function failed(?Throwable $exception): void
    {
        app(FailOrderAction::class)->execute($this->orderId, $exception);
    }
}
