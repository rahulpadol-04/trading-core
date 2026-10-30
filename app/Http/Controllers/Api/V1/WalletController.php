<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class WalletController extends Controller
{
    /**
     * A lock-free snapshot: readers never block writers.
     */
    public function __invoke(Request $request): WalletResource
    {
        $user = $request->user();
        $wallet = $user->wallet()->firstOrFail();

        $positions = $user->positions()
            ->with('asset:id,symbol')
            ->where(fn (Builder $query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
            ->orderBy('asset_id')
            ->get();

        return new WalletResource($wallet->setRelation('positions', $positions));
    }
}
