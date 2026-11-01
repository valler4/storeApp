<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderResource;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderItemCancellationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return OrderResource::collection(
            $request->user()->orders()->with('orderItems')->latest()->paginate(15)
        );
    }

    public function show(Request $request, string $orderId)
    {
        return new OrderResource(
            $request->user()->orders()->with('orderItems')->findOrFail($orderId)
        );
    }

    public function store(StoreOrderRequest $request)
    {
        // Only items inside the current user's own cart.
        $cart = $request->user()->cart()->firstOrFail();

        return DB::transaction(function () use ($request, $cart) {

            $cartItems = $cart->cartItems()
                ->whereIn('id', $request->item_ids)
                ->get();

            if ($cartItems->count() < count($request->item_ids)) {
                abort(404, 'One or more items were not found in your cart.');
            }

            $products = Product::whereIn('id', $cartItems->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $failures = [];

            foreach ($cartItems as $item) {
                $product = $products->get($item->product_id);

                $reason = match (true) {
                    $product === null => 'unavailable',
                    $product->user_id === $request->user()->id => 'your own product',
                    default => $product->unavailableReason($item->quantity),
                };

                if ($reason !== null) {
                    $failures[] = [
                        'item_id' => $item->id,
                        'product_name' => $product?->name,
                        'reason' => $reason,
                    ];
                }
            }

            // All or nothing: nothing has been written yet.
            if ($failures) {
                return response()->json([
                    'message' => 'Some items cannot be purchased.',
                    'items' => $failures,
                ], 422);
            }

            // Total is calculated on the server, in cents, from the locked prices.
            $totalCents = $cartItems->sum(
                fn($item) => $products->get($item->product_id)->priceInCents() * $item->quantity
            );

            $order = $request->user()->orders()->create([
                ...$request->safe()->only(['recipient_name', 'shipping_phone', 'shipping_address']),
                'total_price' => number_format($totalCents / 100, 2, '.', ''),
            ]);

            foreach ($cartItems as $item) {
                $product = $products->get($item->product_id);

                $order->orderItems()->create([
                    'product_id' => $product->id,
                    'seller_id' => $product->user_id,
                    'product_name' => $product->name,
                    'quantity' => $item->quantity,
                    'price_at_purchase' => $product->price,
                ]);

                $product->decrement('stock', $item->quantity);
            }

            return new OrderResource($order->load('orderItems'));
        });
    }

    public function cancel(Request $request, OrderItemCancellationService $service, string $itemId)
    {
        $item = OrderItem::whereHas('order', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($itemId);

        if (! $service->cancel($item)) {
            return response()->json(['message' => 'Only pending items can be cancelled.'], 422);
        }

        return new OrderItemResource($item->fresh());
    }
}
