<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\PlaceOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class OrderController extends Controller
{
    public function store(PlaceOrderRequest $request, PlaceOrderAction $action): JsonResponse
    {
        $result = $action->execute($request->toData());

        return response()
            ->json($result->body, $result->status)
            ->header('Idempotency-Replayed', $result->replayed ? 'true' : 'false');
    }

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->filters();

        $orders = Order::query()
            ->ownedBy($request->user())
            ->when($filters->status, fn ($query, $status) => $query->where('status', $status))
            ->when($filters->assetId, fn ($query, $assetId) => $query->where('asset_id', $assetId))
            ->with('asset:id,symbol')
            ->orderByDesc('id')
            ->cursorPaginate($filters->perPage)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(['asset:id,symbol', 'executions']));
    }

    public function destroy(Request $request, Order $order, CancelOrderAction $action): OrderResource
    {
        Gate::authorize('cancel', $order);

        $order = $action->execute($order, CancelOrderAction::REASON_USER, $request->user()?->id, $request->ip());

        return new OrderResource($order->load('asset:id,symbol'));
    }
}
