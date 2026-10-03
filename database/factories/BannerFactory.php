<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Banner>
 */
class BannerFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Banner>
     */
    protected $model = Banner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => ['en' => 'Every occasion has a gift', 'ar' => 'لكل مناسبة هدية'],
            'subtitle' => ['en' => 'Curated choices for everyone', 'ar' => 'اختيارات منتقاة للجميع'],
            'button_text' => ['en' => 'Discover now', 'ar' => 'اكتشف الآن'],
            'image' => 'banners/'.fake()->uuid().'.jpg',
            'link' => null,
            'sort_order' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
