<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


#[Fillable(['user_id', 'name',
            'description','price','stock','main_image',
            'is_active', 'is_hidden'])]
class Product extends Model
{
    use HasFactory , HasUlids;
}
