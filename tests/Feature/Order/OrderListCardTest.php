<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /api/v1/orders: each order carries what its "My orders" card shows.
 */
class OrderListCardTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 14:00:00');

        $this->customer = User::factory()->customer()->create();
        $this->store = User::factory()->storeOwner()->create();
        $profile = StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'عطوري', 'logo' => 'stores/logo.png']);
        StoreBranch::factory()->create(['store_profile_id' => $profile->id, 'address' => 'الرياض، حي الياسمين', 'is_main' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function order(string $status, string $createdAt, int $lines = 1): Order
    {
        $order = Order::create([
            'order_number' => 'GFT-10254', 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh', 'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => $status,
        ]);
        foreach (range(1, $lines) as $i) {
            $order->items()->create(['product_name' => "Item {$i}", 'product_image' => "products/{$i}.jpg", 'unit_price' => 10, 'quantity' => 1, 'subtotal' => 10]);
        }
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order;
    }

    public function test_an_order_card_has_exactly_the_card_fields(): void
    {
        $this->order(Order::STATUS_DELIVERED, '2026-10-03 10:30:00', lines: 5);

        $card = $this->actingAs($this->customer, 'sanctum')
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.items.0.store_name', 'عطوري')
            ->assertJsonPath('data.items.0.store_logo', fn ($url) => str_ends_with($url, 'stores/logo.png'))
            ->assertJsonPath('data.items.0.store_branch', 'الرياض، حي الياسمين')
            ->assertJsonPath('data.items.0.order_reference', '#GFT-10254')
            ->assertJsonPath('data.items.0.created_at_formatted', 'اليوم، 10:30 ص')
            ->assertJsonPath('data.items.0.status_key', 'delivered')
            ->assertJsonPath('data.items.0.status_label', 'مكتمل')
            ->assertJsonCount(3, 'data.items.0.items_preview')
            ->assertJsonPath('data.items.0.items_preview.0.image', fn ($url) => str_ends_with($url, 'products/1.jpg'))
            ->assertJsonPath('data.items.0.remaining_items_count', 2)
            ->assertJsonPath('data.items.0.action_type', 'view_details')
            ->assertJsonPath('data.items.0.action_label', 'تفاصيل طلب')
            ->json('data.items.0');

        $this->assertSame([
            'id', 'store_name', 'store_logo', 'store_branch', 'order_reference', 'created_at_formatted',
            'status_key', 'status_label', 'items_preview', 'remaining_items_count', 'action_type', 'action_label',
        ], array_keys($card));
    }

    public function test_an_active_order_card_also_only_offers_the_details_button(): void
    {
        $this->order(Order::STATUS_OUT_FOR_DELIVERY, '2026-10-02 21:15:00');

        $this->actingAs($this->customer, 'sanctum')
            ->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.items.0.created_at_formatted', 'Yesterday, 9:15 PM')
            ->assertJsonPath('data.items.0.status_label', 'On the way')
            ->assertJsonPath('data.items.0.remaining_items_count', 0)
            ->assertJsonPath('data.items.0.action_type', 'view_details')
            ->assertJsonPath('data.items.0.action_label', 'Order details');
    }
}
