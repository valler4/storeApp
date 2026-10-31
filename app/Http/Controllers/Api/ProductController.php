<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $products = Product::where('is_hidden', 0)->latest()->paginate(15);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Product::class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0|max:99999999.99',
            'stock' => 'required|integer|min:0',
            'main_image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'is_active' => 'required|boolean',
            'is_hidden' => 'required|boolean',
        ]);

        $validated['main_image'] = $this->storeImage($request->file('main_image'), $validated['name']);
        $validated['user_id'] = $request->user()->id;

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'product' => $product,
        ], 201);
    }

    public function show(Product $product)
    {
        $user = auth('sanctum')->user();

        if ($product->is_hidden && $user?->id !== $product->user_id) {
            abort(404);
        }

        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0|max:99999999.99',
            'stock' => 'required|integer|min:0',
            'main_image' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:2048',
            'is_active' => 'required|boolean',
            'is_hidden' => 'required|boolean',
        ]);

        if ($request->hasFile('main_image')) {
            Storage::disk('public')->delete($product->main_image);
            $data['main_image'] = $this->storeImage($request->file('main_image'), $data['name']);
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'product' => $product,
        ]);
    }

    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);

        CartItem::where('product_id', $product->id)->delete();

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }

    private function storeImage(UploadedFile $image, string $name): string
    {
        $filename = Str::slug($name).'_'.Str::random(8).'.'.$image->extension();

        return $image->storeAs('products', $filename, 'public');
    }

    public function mine(Request $request)
    {
        return response()->json(
            $request->user()->products()->latest()->paginate(15)
        );
    }
}
