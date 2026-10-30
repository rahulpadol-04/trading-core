<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Every worker boots first, then all of them start at the same wall-clock instant.
 */
function waitForStart(string $startAt): void
{
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
}
