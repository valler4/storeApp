<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    public function couponUsages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
