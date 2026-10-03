<?php

namespace Database\Factories;

use App\Models\Addon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Addon>
 */
class AddonFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Addon>
     */
    protected $model = Addon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id'       => User::factory()->storeOwner(),
            'name'           => fake()->randomElement(['Chocolate Box', 'Greeting Card', 'Gift Wrap', 'Balloons', 'Candle']),
            'price'          => fake()->randomFloat(2, 5, 80),
            'stock_quantity' => fake()->numberBetween(1, 50),
            'image'          => 'addons/'.fake()->uuid().'.jpg',
            'is_active'      => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => ['stock_quantity' => 0]);
    }
}
