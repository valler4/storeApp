<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\CartResource;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request)
    {
        $cart = $request->user()->cart()->with('cartItems.product')->firstOrNew();

        return new CartResource($cart);
    }

    public function store(AddCartItemRequest $request)
    {

        $product = Product::where('is_hidden', false)->findOrFail($request->product_id);
        $cart = $request->user()->cart()->firstOrCreate();

        $item = $cart->cartItems()->firstOrCreate(
            ['product_id' => $product->id],
            ['quantity' => 1]
        );

        return new CartItemResource($item);

    }

    public function update(UpdateCartItemRequest $request, string $itemId)
    {
        $cart = $request->user()->cart()->firstOrFail();
        $item = $cart->cartItems()->findOrFail($itemId);
        $item->update(['quantity' => $request->quantity]);

        return new CartItemResource($item);
    }

    public function remove(Request $request, $itemId)
    {
        $cart = $request->user()->cart()->firstOrFail();
        $cart->cartItems()->findOrFail($itemId)->delete();

        return  response()->noContent();
    }
}
