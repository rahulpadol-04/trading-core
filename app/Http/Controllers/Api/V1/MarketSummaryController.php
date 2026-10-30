<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MarketSummaryCache;
use Illuminate\Http\JsonResponse;

final class MarketSummaryController extends Controller
{
    public function __invoke(MarketSummaryCache $cache): JsonResponse
    {
        return response()
            ->json(['data' => $cache->get()])
            ->header('Cache-Control', 'private, max-age=5');
    }
}
