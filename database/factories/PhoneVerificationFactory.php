<?php

namespace Database\Factories;

use App\Models\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhoneVerification>
 */
class PhoneVerificationFactory extends Factory
{
    /**
     * @var class-string<\App\Models\PhoneVerification>
     */
    protected $model = PhoneVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_code' => '966',
            'phone'        => '9665'.fake()->numerify('########'),
            'name'         => null,
            'otp_code'     => fake()->numerify('####'),
            'expires_at'   => now()->addMinutes(10),
            'verified_at'  => null,
        ];
    }

    /**
     * رمز تم استهلاكه (الرقم موثّق)
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => ['verified_at' => now()]);
    }

    /**
     * رمز منتهي الصلاحية
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinutes(10)]);
    }

    /**
     * طلب تسجيل يحمل اسم صاحب الحساب
     */
    public function forRegistration(string $name): static
    {
        return $this->state(fn (array $attributes) => ['name' => $name]);
    }

    public function forPhone(string $phone): static
    {
        return $this->state(fn (array $attributes) => ['phone' => $phone]);
    }
}
