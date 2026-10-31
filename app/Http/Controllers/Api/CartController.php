<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request)
    {
        $cart = $request->user()->cart()->with('cartItems.product')->first();

        if (! $cart) {
            return response()->json(['items' => [], 'total_items' => 0, 'total_price' => '0.00', 'unavailable_count' => 0]);
        }

        $cart->cartItems->each(function ($item) {
            $reason = $item->unavailableReason();

            $item->available = $reason === null;
            $item->reason = $reason;
        });

        $available = $cart->cartItems->where('available', true);

        $cart->total_items = $available->sum('quantity');
        $cart->total_price = number_format(
            $available->sum(fn ($item) => $item->product->price * $item->quantity),
            2, '.', ''
        );
        $cart->unavailable_count = $cart->cartItems->count() - $available->count();

        return response()->json($cart);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $product = Product::where('is_hidden', false)->findOrFail($request->product_id);

        $cart = $request->user()->cart()->firstOrCreate();

        $item = $cart->cartItems()->firstOrCreate(
            ['product_id' => $product->id],
            ['quantity' => 1]
        );

        return response()->json($item, $item->wasRecentlyCreated ? 201 : 200);

    }

    public function update(Request $request, string $itemId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1|max:99',
        ]);

        $cart = $request->user()->cart()->firstOrFail();

        $item = $cart->cartItems()->findOrFail($itemId);

        $item->update(['quantity' => $request->quantity]);

        return response()->json($item);
    }

    public function remove(Request $request, $itemId)
    {
        $cart = $request->user()->cart()->firstOrFail();

        $cart->cartItems()->findOrFail($itemId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart',
        ]);
    }
}
