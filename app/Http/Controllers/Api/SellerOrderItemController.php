<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderItemStatusRequest;
use App\Http\Resources\OrderItemResource;
use App\Models\OrderItem;
use App\Services\OrderItemCancellationService;
use Illuminate\Http\Request;

class SellerOrderItemController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => 'sometimes|in:pending,shipped,delivered,cancelled']);

        return OrderItemResource::collection(
            OrderItem::where('seller_id', $request->user()->id)
                ->when($request->status, fn($q, $status) => $q->where('status', $status))
                ->with('order:id,recipient_name,shipping_phone,shipping_address')
                ->latest()
                ->paginate(15)
        );
    }
    public function update(UpdateOrderItemStatusRequest $request, string $itemId)
    {
        $item = OrderItem::where('seller_id', $request->user()->id)->findOrFail($itemId);

        $next = ['pending' => 'shipped', 'shipped' => 'delivered'];

        if (($next[$item->status] ?? null) !== $request->status) {
            return response()->json([
                'message' => "Cannot change status from {$item->status} to {$request->status}.",
            ], 422);
        }

        $item->update(['status' => $request->status]);

        return new OrderItemResource($item);
    }

    public function cancel(Request $request, OrderItemCancellationService $service, string $itemId)
    {
        $item = OrderItem::where('seller_id', $request->user()->id)->findOrFail($itemId);

        if (! $service->cancel($item)) {
            return response()->json(['message' => 'Only pending items can be cancelled.'], 422);
        }

        return response()->json($item->fresh());
    }
}
