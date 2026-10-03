<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreProfile>
 */
class StoreProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\StoreProfile>
     */
    protected $model = StoreProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->storeOwner(),
            'store_name' => 'متجر '.fake()->company(),
            'category_id' => Category::factory(),
            'phone' => '9665'.fake()->numerify('########'),
            'email' => fake()->unique()->companyEmail(),
            'description' => fake()->realText(150),
            'iban' => $this->iban(),
            'iban_certificate_file' => 'documents/iban/'.fake()->uuid().'.pdf',
            'logo' => 'stores/logos/'.fake()->uuid().'.png',
            'cover_image' => 'stores/covers/'.fake()->uuid().'.jpg',
            'commercial_register_file' => 'documents/cr/'.fake()->uuid().'.pdf',
            'working_hours' => $this->workingHours(),
            'delivery_fee' => fake()->boolean(30) ? fake()->randomFloat(2, 5, 25) : null,
            'preparation_time' => fake()->randomElement([15, 20, 30, 45]),
            'rating_avg' => fake()->randomFloat(2, 3, 5),
            'rating_count' => fake()->numberBetween(0, 500),
            'is_featured' => false,
            'status' => 'approved',
            'submitted_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'rejection_reason' => null,
        ];
    }

    /**
     * رقم آيبان سعودي وهمي
     */
    protected function iban(): string
    {
        return 'SA'.fake()->numerify('##').'8000'.fake()->numerify('################');
    }

    /**
     * أوقات عمل واقعية لكل يوم من أيام الأسبوع
     *
     * @return array<string, array<string, mixed>>
     */
    protected function workingHours(): array
    {
        $days = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $hours = [];

        foreach ($days as $day) {
            $isClosed = $day === 'friday';

            $hours[$day] = [
                'is_open' => ! $isClosed,
                'from' => $isClosed ? null : '09:00',
                'to' => $isClosed ? null : '23:00',
            ];
        }

        return $hours;
    }

    /**
     * متجر مميز يظهر في الشاشة الرئيسية
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
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
            'rejection_reason' => 'المستندات المرفقة غير واضحة، يرجى إعادة رفعها.',
        ]);
    }
}
