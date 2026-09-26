<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;

class CartController extends Controller
{
    public function show(Request $request)
    {
        $cart = Cart::with('cartItems.product')->firstOrCreate(['user_id' => $request->user()->id]);
        return response()->json($cart);
    }

    public function addItem(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $request->user()->id]);

        $product = Product::findOrFail($request->product_id);

        if ($product->stock < $request->quantity) {
            return response()->json(['error' => 'Not enough stock available'], 400);
        }

        $cartItem = CartItem::updateOrCreate([
            'cart_id' => $cart->id,
            'product_id' => $request->product_id,
            'quantity' => $request->quantity,
        ]);

        $product->increment('reserved_stock', $request->quantity);

        return response()->json([
            'success' => true,
            'cart_item' => $cartItem,
        ]);
    }

    public function removeItem(Request $request, $itemId)
    {
        $cartItem = CartItem::findOrFail($itemId);

        $cartItem->product->decrement('reserved_stock', $cartItem->quantity);

        $cartItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart',
        ]);
    }

    public function updateItem(Request $request, $itemId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = CartItem::findOrFail($itemId);
        $product = $cartItem->product;

        $availableForThisUpdate = $product->available_stock + $cartItem->quantity;

        if ($availableForThisUpdate < $request->quantity) {
            return response()->json(['error' => 'Not enough stock available'], 400);
        }

        $product->decrement('reserved_stock', $cartItem->quantity);
        $cartItem->update(['quantity' => $request->quantity]);
        $product->increment('reserved_stock', $request->quantity);

        return response()->json([
            'success' => true,
            'cart_item' => $cartItem,
        ]);
    }
}
