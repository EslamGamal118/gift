<?php

namespace Database\Factories;

use App\Models\Otp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Otp>
 */
class OtpFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Otp>
     */
    protected $model = Otp::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '9665'.fake()->numerify('########'),
            'code' => fake()->numerify('######'),
            'type' => fake()->randomElement(['login', 'register', 'reset_password']),
            'is_used' => false,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    /**
     * رمز مستخدم بالفعل
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes) => ['is_used' => true]);
    }

    /**
     * رمز منتهي الصلاحية
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinutes(10),
        ]);
    }

    /**
     * ربط الرمز برقم جوال مستخدم محدد
     */
    public function forPhone(string $phone): static
    {
        return $this->state(fn (array $attributes) => ['phone' => $phone]);
    }
}
