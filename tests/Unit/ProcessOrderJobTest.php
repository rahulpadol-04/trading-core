<?php

declare(strict_types=1);

use App\Jobs\ProcessOrderJob;

it('runs on the orders queue with retries, backoff and a timeout below retry_after', function () {
    $job = new ProcessOrderJob(1);

    expect($job->queue)->toBe('orders')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->not->toBeEmpty()
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->uniqueId())->toBe('1');
});
