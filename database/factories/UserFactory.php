<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\User>
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '9665'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'avatar' => null,
            'user_type' => 'customer',
            'status' => 'active',
            'phone_verified_at' => now(),
        ];
    }

    /**
     * حساب لم يتم توثيق رقم جواله بعد
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
        ]);
    }

    /**
     * حساب محظور
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blocked',
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => ['user_type' => 'customer']);
    }

    /**
     * حساب صاحب متجر
     *
     * ملاحظة: الاسم storeOwner وليس store لأن Factory::store() محجوز في Laravel.
     */
    public function storeOwner(): static
    {
        return $this->state(fn (array $attributes) => ['user_type' => 'store']);
    }

    public function captain(): static
    {
        return $this->state(fn (array $attributes) => ['user_type' => 'captain']);
    }

    public function shopper(): static
    {
        return $this->state(fn (array $attributes) => ['user_type' => 'shopper']);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['user_type' => 'admin']);
    }
}
