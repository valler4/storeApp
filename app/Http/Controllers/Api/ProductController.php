<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
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
        return ProductResource::collection(
            Product::where('is_hidden', false)->latest()->paginate(15)
        );
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $data['main_image'] = $this->storeImage($request->file('main_image'), $data['name']);
        $data['user_id'] = $request->user()->id;

        return new ProductResource(Product::create($data));
    }

    public function show(Product $product)
    {
        $user = auth('sanctum')->user();

        if ($product->is_hidden && $user?->id !== $product->user_id) {
            abort(404);
        }

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validated();

        if ($request->hasFile('main_image')) {
            Storage::disk('public')->delete($product->main_image);
            $data['main_image'] = $this->storeImage($request->file('main_image'), $data['name']);
        }

        $product->update($data);

        return new ProductResource($product);
    }

    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);

        CartItem::where('product_id', $product->id)->delete();

        $product->delete();

        return response()->noContent();
    }

    private function storeImage(UploadedFile $image, string $name): string
    {
        $filename = Str::slug($name) . '_' . Str::random(8) . '.' . $image->extension();

        return $image->storeAs('products', $filename, 'public');
    }

    public function mine(Request $request)
    {
        return ProductResource::collection($request->user()->products()->latest()->paginate(15));
    }
}
