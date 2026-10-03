<?php

namespace Database\Factories;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomOrderItem>
 */
class CustomOrderItemFactory extends Factory
{
    /**
     * @var class-string<\App\Models\CustomOrderItem>
     */
    protected $model = CustomOrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $min = fake()->randomFloat(2, 20, 200);

        return [
            'custom_order_id'    => CustomOrder::factory(),
            'product_name'       => fake()->randomElement(['Perfume', 'Chocolate box', 'Flower bouquet', 'Watch', 'Scarf']),
            'description'        => fake()->sentence(),
            'quantity'           => fake()->numberBetween(1, 3),
            'expected_price_min' => $min,
            'expected_price_max' => $min + fake()->randomFloat(2, 10, 100),
            'sort_order'         => 0,
        ];
    }
}
