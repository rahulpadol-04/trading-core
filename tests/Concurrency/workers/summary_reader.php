<?php

declare(strict_types=1);

use App\Services\MarketSummaryCache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';

[, $startAt] = $argv;

waitForStart($startAt);

DB::enableQueryLog();
app(MarketSummaryCache::class)->get();

echo json_encode(['queries' => count(DB::getQueryLog())]);
