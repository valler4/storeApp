<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'quantity'])]
class CartItem extends Model
{
    use HasFactory, HasUlids;

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unavailableReason(): ?string
    {
        if (! $this->product) {
            return 'unavailable';
        }

        return $this->product->unavailableReason($this->quantity);
    }

    protected function casts(): array
    {
        return [
            'price_at_purchase' => 'decimal:2',
        ];
    }
}
