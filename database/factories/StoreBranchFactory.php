<?php

namespace Database\Factories;

use App\Models\StoreBranch;
use App\Models\StoreProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreBranch>
 */
class StoreBranchFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\StoreBranch>
     */
    protected $model = StoreBranch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Real serviced city so the branch sits near its centroid (± ~10 km)
        $key  = fake()->randomElement(array_keys(config('cities.list')));
        $city = config("cities.list.{$key}");

        return [
            'store_profile_id' => StoreProfile::factory(),
            'name' => 'فرع '.$city['name']['ar'],
            'address' => $city['name']['ar'].'، حي '.fake()->randomElement(['النرجس', 'الملقا', 'العليا', 'الروضة', 'السلامة']),
            'city' => $key,
            'latitude' => $city['latitude'] + fake()->randomFloat(4, -0.09, 0.09),
            'longitude' => $city['longitude'] + fake()->randomFloat(4, -0.09, 0.09),
            'phone' => '9665'.fake()->numerify('########'),
            'is_main' => false,
            'is_active' => true,
        ];
    }

    /**
     * الفرع الرئيسي
     */
    public function main(): static
    {
        return $this->state(fn (array $attributes) => ['is_main' => true]);
    }

    /**
     * فرع عند إحداثيات محددة (للاختبارات)
     */
    public function at(float $latitude, float $longitude, ?string $city = null): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'city' => $city ?? $attributes['city'] ?? null,
        ]);
    }

    /**
     * فرع بدون إحداثيات
     */
    public function unlocated(): static
    {
        return $this->state(fn (array $attributes) => ['latitude' => null, 'longitude' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
