<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Category>
     */
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return [
            'name' => [
                'en' => ucfirst($word),
                'ar' => 'تصنيف '.$word,
            ],
            'image' => 'categories/'.fake()->uuid().'.png',
            'is_active' => true,
            'is_special' => false,
        ];
    }

    /**
     * تصنيف خاص (هدايا رقمية / أونلاين) يظهر في قسم مستقل بالشاشة الرئيسية
     */
    public function special(): static
    {
        return $this->state(fn (array $attributes) => ['is_special' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
