<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\OrderItemCancellationService;
use Illuminate\Http\Request;

class SellerOrderItemController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'sometimes|in:pending,shipped,delivered,cancelled',
        ]);

        $items = OrderItem::where('seller_id', $request->user()->id)
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->with('order:id,recipient_name,shipping_phone,shipping_address')
            ->latest()
            ->paginate(15);

        return response()->json($items);
    }

    public function update(Request $request, string $itemId)
    {
        $request->validate([
            'status' => 'required|in:shipped,delivered',
        ]);

        $item = OrderItem::where('seller_id', $request->user()->id)->findOrFail($itemId);

        $next = ['pending' => 'shipped', 'shipped' => 'delivered'];

        if (($next[$item->status] ?? null) !== $request->status) {
            return response()->json([
                'message' => "Cannot change status from {$item->status} to {$request->status}.",
            ], 422);
        }

        $item->status = $request->status;
        $item->save();

        return response()->json($item);
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
