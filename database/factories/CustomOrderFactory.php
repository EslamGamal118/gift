<?php

namespace Database\Factories;

use App\Models\CustomOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomOrder>
 */
class CustomOrderFactory extends Factory
{
    /**
     * @var class-string<\App\Models\CustomOrder>
     */
    protected $model = CustomOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city     = fake()->randomElement(['Riyadh', 'Jeddah', 'Dammam']);
        $district = fake()->randomElement(['Olaya', 'Al Malqa', 'Al Rawdah']);
        $street   = fake()->streetName();
        $building = (string) fake()->numberBetween(1, 999);

        return [
            'order_number'             => CustomOrder::generateNumber(),
            'user_id'                  => User::factory()->customer(),
            'shopper_id'               => null,
            'assignment_mode'          => null,
            'delivery_name'            => fake()->name(),
            'delivery_phone'           => '05'.fake()->numerify('########'),
            'delivery_location_name'   => 'Home',
            'delivery_city'            => $city,
            'delivery_district'        => $district,
            'delivery_street'          => $street,
            'delivery_building_number' => $building,
            'delivery_address'         => "{$building}, {$street}, {$district}, {$city}",
            'delivery_latitude'        => fake()->latitude(16.5, 32.0),
            'delivery_longitude'       => fake()->longitude(34.5, 55.5),
            'delivery_at'              => now()->addDay()->setTime(18, 0),
            'notes'                    => null,
            'currency'                 => 'SAR',
            'budget_min'               => 100,
            'budget_max'               => 300,
            'status'                   => CustomOrder::STATUS_DRAFT,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => CustomOrder::STATUS_PENDING, 'submitted_at' => now()]);
    }

    public function assignedTo(User $shopper): static
    {
        return $this->state(fn () => [
            'shopper_id'      => $shopper->id,
            'assignment_mode' => CustomOrder::MODE_DIRECT,
            'status'          => CustomOrder::STATUS_PENDING,
            'submitted_at'    => now(),
            'assigned_at'     => now(),
        ]);
    }

    public function bidding(): static
    {
        return $this->state(fn () => [
            'shopper_id'        => null,
            'assignment_mode'   => CustomOrder::MODE_BIDDING,
            'status'            => CustomOrder::STATUS_PENDING,
            'submitted_at'      => now(),
            'bidding_opened_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'       => CustomOrder::STATUS_COMPLETED,
            'submitted_at' => now()->subDays(2),
            'accepted_at'  => now()->subDays(2),
            'started_at'   => now()->subDay(),
            'completed_at' => now(),
            'final_amount' => 250,
        ]);
    }

    /**
     * Purchased and paid by the customer; `$status` is where its delivery is.
     */
    public function paid(string $status = CustomOrder::STATUS_PAID): static
    {
        return $this->state(fn () => [
            'status'         => $status,
            'submitted_at'   => now()->subDays(2),
            'accepted_at'    => now()->subDays(2),
            'started_at'     => now()->subDay(),
            'purchased_at'   => now()->subDay(),
            'final_amount'   => 250,
            'total_amount'   => 287.5,
            'payment_status' => CustomOrder::PAYMENT_PAID,
            'payment_method' => 'alrajhi',
            'paid_at'        => now()->subHours(3),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'       => CustomOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => CustomOrder::ACTOR_CUSTOMER,
        ]);
    }
}
