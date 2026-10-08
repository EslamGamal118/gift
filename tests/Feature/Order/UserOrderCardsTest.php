<?php

namespace Tests\Feature\Order;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\Order;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "My orders" cards ready to render (GET /user/orders, /user/custom-orders):
 * tabs with counts, "#" reference, status badge with a tone, "city، حي district",
 * product images with "+N", and the card's buttons.
 */
class UserOrderCardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id]);
        Sanctum::actingAs($this->customer);
    }

    protected function order(string $status, int $items = 1, array $attributes = []): Order
    {
        $order = Order::create($attributes + [
            'order_number' => Order::generateNumber(), 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_city' => 'riyadh', 'shipping_district' => 'الياسمين', 'shipping_street' => '-',
            'shipping_building_number' => '-', 'shipping_address' => '-',
            'delivery_type' => 'instant', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => $status,
        ]);

        foreach (range(1, $items) as $n) {
            $order->items()->create(['product_name' => "Item {$n}", 'product_image' => "products/{$n}.jpg", 'unit_price' => 20, 'quantity' => 1, 'subtotal' => 20]);
        }

        return $order;
    }

    public function test_a_store_order_card_has_everything_the_design_shows(): void
    {
        $order = $this->order(Order::STATUS_OUT_FOR_DELIVERY, 5);
        $this->order(Order::STATUS_DELIVERED);
        $this->order(Order::STATUS_PENDING);

        $data = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/user/orders?tab=active')->assertOk()->json('data');

        $this->assertSame(['tab' => 'active', 'search' => null], $data['filter']);
        $this->assertSame([
            ['key' => 'active', 'label' => 'الحالية', 'count' => 2],
            ['key' => 'history', 'label' => 'السابقة', 'count' => 1],
        ], $data['tabs']);

        $card = collect($data['items'])->firstWhere('id', $order->id);

        // Original fields are all still there
        foreach (['order_type', 'order_number', 'status', 'status_label', 'tab', 'city', 'district', 'items_count', 'items', 'pricing', 'payment_status', 'delivery', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $card);
        }
        $this->assertCount(5, $card['items']);

        $this->assertSame('#'.$order->order_number, $card['order_reference']);
        $this->assertSame(['key' => 'out_for_delivery', 'label' => 'في الطريق', 'tone' => 'primary'], $card['status_badge']);
        $this->assertSame('الرياض، حي الياسمين', $card['location_label']);
        $this->assertCount(3, $card['items_preview']);
        $this->assertStringEndsWith('products/1.jpg', $card['items_preview'][0]['image']);
        $this->assertSame([2, '+2'], [$card['remaining_items_count'], $card['remaining_items_label']]);
        $this->assertSame([
            ['key' => 'track', 'label' => 'تتبع الطلب', 'is_primary' => true],
            ['key' => 'details', 'label' => 'تفاصيل الطلب', 'is_primary' => false],
        ], $card['actions']);

        $pending = collect($data['items'])->firstWhere('status', Order::STATUS_PENDING);
        $this->assertSame(['key' => 'pending', 'label' => 'قيد المراجعة', 'tone' => 'warning'], $pending['status_badge']);
        $this->assertNull($pending['remaining_items_label']);
    }

    public function test_finished_orders_only_offer_the_details_and_english_labels_follow_the_language(): void
    {
        $this->order(Order::STATUS_DELIVERED, 1, ['shipping_district' => 'حي النرجس']);

        $card = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/user/orders?tab=history')->assertOk()->json('data.items.0');

        $this->assertSame('success', $card['status_badge']['tone']);
        $this->assertSame('Riyadh, النرجس', $card['location_label']);   // the "حي" prefix is not doubled or forced
        $this->assertSame([['key' => 'details', 'label' => 'Order details', 'is_primary' => true]], $card['actions']);
    }

    public function test_a_custom_order_card_uses_the_same_display_fields(): void
    {
        $order = CustomOrder::factory()->paid(CustomOrder::STATUS_ARRIVED_TO_DROPOFF)->create([
            'user_id' => $this->customer->id, 'delivery_city' => 'Riyadh', 'delivery_district' => 'الملقا',
        ]);
        CustomOrderItem::factory()->count(4)->create(['custom_order_id' => $order->id]);

        $data = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/user/custom-orders')->assertOk()->json('data');

        $this->assertSame([1, 0], array_column($data['tabs'], 'count'));
        $card = $data['items'][0];
        $this->assertSame(['key' => 'arrived_to_dropoff', 'label' => 'وصل المندوب لموقع التسليم', 'tone' => 'primary'], $card['status_badge']);
        $this->assertSame('الرياض، حي الملقا', $card['location_label']);
        $this->assertCount(3, $card['items_preview']);
        $this->assertSame('+1', $card['remaining_items_label']);
        $this->assertSame('track', $card['actions'][0]['key']);
    }
}
