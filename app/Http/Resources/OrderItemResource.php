<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'seller_id' => $this->seller_id,
            'product_name' => $this->product_name,
            'quantity' => $this->quantity,
            'price_at_purchase' => $this->price_at_purchase,
            'status' => $this->status,
            // البائع بس محتاج بيانات الشحن، وبتظهر لما نحمّل الـ order
            'shipping' => $this->whenLoaded('order', fn() => [
                'recipient_name' => $this->order->recipient_name,
                'phone' => $this->order->shipping_phone,
                'address' => $this->order->shipping_address,
            ]),
        ];
    }
}
