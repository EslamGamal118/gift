<?php

namespace Tests\Feature\Seeders;

use App\Models\CustomOrder;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomOrderSeeder;
use Database\Seeders\DeliverySlotSeeder;
use Database\Seeders\ShopperProfileSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomOrderSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_consistent_orders_in_every_status_for_both_apps(): void
    {
        $this->seed([CategorySeeder::class, DeliverySlotSeeder::class, UserSeeder::class, ShopperProfileSeeder::class, CustomOrderSeeder::class]);

        $orders = CustomOrder::query()->with(['items', 'bids'])->get();

        $this->assertEqualsCanonicalizing(CustomOrder::STATUSES, $orders->pluck('status')->unique()->values()->all());

        foreach ($orders as $order) {
            $this->assertContains($order->items->count(), [2, 3, 4]);
            $this->assertEquals($order->items->sum(fn ($i) => $i->expected_price_min * $i->quantity), (float) $order->budget_min);
            $this->assertEquals($order->items->sum(fn ($i) => $i->expected_price_max * $i->quantity), (float) $order->budget_max);
            $this->assertSame('SAR', $order->currency);
            $this->assertSame($order->status === CustomOrder::STATUS_DRAFT, $order->submitted_at === null);

            if ($order->status !== CustomOrder::STATUS_DRAFT) {
                $this->assertNotNull($order->delivery_city);
                $this->assertNotNull($order->delivery_address);
            }
            if ($order->status === CustomOrder::STATUS_COMPLETED) {
                $this->assertNotNull($order->completed_at);
                $this->assertTrue($order->final_amount >= $order->budget_min && $order->final_amount <= $order->budget_max);
            }
            if ($order->status === CustomOrder::STATUS_CANCELLED) {
                $this->assertNotNull($order->cancelled_at);
                $this->assertNotNull($order->cancellation_reason);
            }
            if ($order->isBidding()) {
                $this->assertNull($order->shopper_id);
                $this->assertGreaterThanOrEqual(2, $order->bids->count());
            }
        }

        // The test accounts see both tabs
        Sanctum::actingAs(User::query()->where('phone', CustomOrderSeeder::TEST_CUSTOMER_PHONE)->firstOrFail());
        $this->assertGreaterThan(0, $this->getJson('/api/v1/user/custom-orders?tab=active')->assertOk()->json('data.pagination.total'));
        $this->assertGreaterThan(0, $this->getJson('/api/v1/user/custom-orders?tab=history')->assertOk()->json('data.pagination.total'));

        Sanctum::actingAs(User::query()->where('phone', CustomOrderSeeder::TEST_SHOPPER_PHONE)->firstOrFail());
        $counts = $this->getJson('/api/v1/shopper/orders')->assertOk()->json('data.counts');
        $this->assertGreaterThan(0, $counts['active']);
        $this->assertGreaterThan(0, $counts['history']);
    }
}
