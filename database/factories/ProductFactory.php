<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'price' => 10,
            'stock' => 5,
            'main_image' => 'products/test.jpg',
            'is_active' => true,
            'is_hidden' => false,
        ];
    }
}
