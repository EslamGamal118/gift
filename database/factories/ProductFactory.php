<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Product>
     */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => User::factory()->storeOwner(),
            'category_id' => Category::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 20, 500),
            'stock_quantity' => fake()->numberBetween(1, 50),
            'expiry_date' => null,
            'image' => 'products/'.fake()->uuid().'.jpg',
            'preparation_time' => fake()->randomElement([15, 20, 30, 45]),
            'rating_avg' => fake()->randomFloat(2, 3, 5),
            'rating_count' => fake()->numberBetween(0, 200),
            'is_featured' => false,
        ];
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => ['stock_quantity' => 0]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expiry_date' => now()->subDay()->toDateString()]);
    }
}
