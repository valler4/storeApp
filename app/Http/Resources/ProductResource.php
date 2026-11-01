<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{

public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'description' => $this->description,
        'price' => $this->price,
        'stock' => $this->stock,
        'main_image' => Storage::disk('public')->url($this->main_image),
        'is_active' => $this->is_active,
        'seller_id' => $this->user_id,
        'created_at' => $this->created_at,
        'is_hidden' => $this->when(
            $request->user('sanctum')?->id === $this->user_id,
            $this->is_hidden
        ),
    ];
}
}
