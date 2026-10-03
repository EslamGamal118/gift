<?php

namespace Tests\Unit;

use App\Models\StoreProfile;
use App\Services\DeliveryCalculatorService;
use Tests\TestCase;

class DeliveryCalculatorServiceTest extends TestCase
{
    protected function calculator(array $pricing = []): DeliveryCalculatorService
    {
        return new DeliveryCalculatorService([
            'currency' => 'SAR',
            'pricing' => $pricing + ['base_fee' => 10, 'base_distance_km' => 3, 'fee_per_extra_km' => 1.5, 'max_fee' => 30],
            'max_distance_km' => 50,
            'average_speed_kmh' => 30,
            'default_preparation_minutes' => 20,
            'buffer_minutes' => 15,
        ]);
    }

    public function test_base_fee_covers_the_base_distance_then_each_started_km_is_charged(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(10.0, $calculator->distanceFee(null));
        $this->assertSame(10.0, $calculator->distanceFee(0.5));
        $this->assertSame(10.0, $calculator->distanceFee(3.0));
        $this->assertSame(11.5, $calculator->distanceFee(3.2));   // 1 started km
        $this->assertSame(13.0, $calculator->distanceFee(5.0));   // 2 km
        $this->assertSame(14.5, $calculator->distanceFee(5.01));  // 3 started km
        $this->assertSame(30.0, $calculator->distanceFee(40));    // capped
    }

    public function test_store_flat_fee_overrides_the_distance_price(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(7.0, $calculator->fee(new StoreProfile(['delivery_fee' => 7]), 20));
        $this->assertSame(13.0, $calculator->fee(new StoreProfile(['delivery_fee' => null]), 5));
    }

    public function test_quote_combines_fee_time_and_availability(): void
    {
        $quote = $this->calculator()->quote(new StoreProfile(['delivery_fee' => null, 'preparation_time' => 25]), 10.0);

        $this->assertSame([
            'distance_km' => 10.0,
            'fee' => 20.5,                        // 10 + 7 km x 1.5
            'currency' => 'SAR',
            'time' => ['min' => 45, 'max' => 60], // 25 prep + 20 travel, +15 buffer
            'available' => true,
            'is_estimated' => true,
        ], $quote);

        $this->assertFalse($this->calculator()->quote(new StoreProfile(), 51.0)['available']);
    }

    public function test_road_distance_applies_the_correction_factor(): void
    {
        config(['stores.delivery.road_factor' => 1.25]);

        $this->assertSame(12.5, $this->calculator()->roadDistanceKm(10));
    }
}
