<?php

namespace Database\Factories;

use App\Models\CaptainProfile;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CaptainProfile>
 */
class CaptainProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\CaptainProfile>
     */
    protected $model = CaptainProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement(['الرياض', 'جدة', 'الدمام', 'مكة المكرمة', 'الخبر']);

        return [
            'user_id' => User::factory()->captain(),
            'iban' => 'SA'.fake()->numerify('##').'8000'.fake()->numerify('################'),
            'iban_certificate_file' => 'documents/iban/'.fake()->uuid().'.pdf',
            'personal_photo' => 'captains/photos/'.fake()->uuid().'.jpg',
            'vehicle_type_id' => VehicleType::factory(),
            'vehicle_model' => fake()->randomElement([
                'Toyota Hilux 2022', 'Hyundai Accent 2021', 'Honda CG 125',
                'Isuzu D-Max 2023', 'Nissan Sunny 2020',
            ]),
            'plate_number' => strtoupper(fake()->bothify('??? ####')),
            'plate_number_file' => 'documents/plates/'.fake()->uuid().'.pdf',
            'license_file' => 'documents/licenses/'.fake()->uuid().'.pdf',
            'plate_image_file' => 'documents/plates/'.fake()->uuid().'.jpg',
            'commercial_register_file' => null,
            'latitude' => fake()->latitude(16.5, 32.0),
            'longitude' => fake()->longitude(34.5, 55.5),
            'address' => $city.'، حي '.fake()->randomElement(['النسيم', 'الشفا', 'قرطبة', 'الصفا']),
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
            'rejection_reason' => 'رخصة القيادة منتهية الصلاحية.',
        ]);
    }
}
