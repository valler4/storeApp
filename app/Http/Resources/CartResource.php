<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->cartItems;
        $available = $items->filter(fn($item) => $item->unavailableReason() === null);

        return [
            'items' => CartItemResource::collection($items),
            'total_items' => $available->sum('quantity'),
            'total_price' => number_format(
                $available->sum(fn($item) => $item->product->price * $item->quantity),
                2,
                '.',
                ''
            ),
            'unavailable_count' => $items->count() - $available->count(),
        ];
    }
}
