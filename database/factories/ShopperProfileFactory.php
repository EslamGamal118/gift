<?php

namespace Database\Factories;

use App\Models\ShopperProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopperProfile>
 */
class ShopperProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\ShopperProfile>
     */
    protected $model = ShopperProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement(['الرياض', 'جدة', 'الدمام', 'مكة المكرمة', 'الخبر']);

        return [
            'user_id' => User::factory()->shopper(),
            'personal_photo' => 'shoppers/photos/'.fake()->uuid().'.jpg',
            'iban' => 'SA'.fake()->numerify('##').'8000'.fake()->numerify('################'),
            'iban_certificate_file' => 'documents/iban/'.fake()->uuid().'.pdf',
            'national_id_number' => fake()->randomElement(['1', '2']).fake()->numerify('#########'),
            'national_id_file' => 'documents/national-id/'.fake()->uuid().'.pdf',
            'freelance_license_file' => 'documents/freelance/'.fake()->uuid().'.pdf',
            'address' => $city.'، حي '.fake()->randomElement(['الياسمين', 'الربيع', 'المروج', 'الفيحاء']),
            'latitude' => fake()->latitude(16.5, 32.0),
            'longitude' => fake()->longitude(34.5, 55.5),
            'status' => 'approved',
            'submitted_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'rejection_reason' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'submitted_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'submitted_at' => now()->subDays(fake()->numberBetween(1, 7)),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'rejection_reason' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'rejection_reason' => 'صورة الهوية الوطنية غير مطابقة.',
        ]);
    }
}
