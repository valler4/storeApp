<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'name',
    'description',
    'price',
    'stock',
    'main_image',
    'is_active',
    'is_hidden',
])]
class Product extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function unavailableReason(int $quantity): ?string
    {
        if ($this->is_hidden) {
            return 'unavailable';
        }

        if (! $this->is_active) {
            return 'closed';
        }

        if ($this->stock < $quantity) {
            return $this->stock <= 0 ? 'out of stock' : "only {$this->stock} left";
        }

        return null;
    }

    public function priceInCents(): int
    {
        return (int) round(((float) $this->price) * 100);
    }
}
