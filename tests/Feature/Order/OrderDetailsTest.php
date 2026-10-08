<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\Order;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /api/v1/orders/{id}: the "Order details" screen.
 */
class OrderDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected StoreProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 14:00:00');
        config(['checkout.support' => ['phone' => '920000000', 'whatsapp' => null, 'email' => null]]);

        $this->customer = User::factory()->customer()->create();
        $this->store = User::factory()->storeOwner()->create();
        $this->profile = StoreProfile::factory()->approved()->create([
            'user_id' => $this->store->id, 'store_name' => 'عطوري', 'category_id' => Category::factory()->create(['name' => ['ar' => 'عطور ومبخرات', 'en' => 'Perfumes']])->id,
            'rating_avg' => 4.46, 'rating_count' => 120,
        ]);
        StoreBranch::factory()->create(['store_profile_id' => $this->profile->id, 'address' => 'الرياض، حي الياسمين', 'is_main' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function order(array $attributes): Order
    {
        $order = Order::create($attributes + [
            'order_number' => 'GFT-10254', 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'حي الياسمين', 'shipping_street' => 'شارع الزهور', 'shipping_building_number' => '45',
            'shipping_address' => 'الرياض، حي الياسمين، شارع الزهور، رقم 45',
            'delivery_type' => 'scheduled', 'delivery_date' => '2026-10-03',
            'delivery_window_start' => '2026-10-03 10:30:00', 'delivery_window_end' => '2026-10-03 11:00:00',
            'currency' => 'SAR', 'subtotal' => 500, 'delivery_fee' => 25, 'express_fee' => 0, 'discount_amount' => 50,
            'tax_rate' => 0.15, 'tax_amount' => 71.25, 'total_amount' => 546.25,
            'payment_status' => Order::PAYMENT_PAID, 'paid_at' => '2026-10-03 10:00:00',
        ]);
        $line = $order->items()->create(['product_name' => 'باقة ورد فاخرة', 'product_image' => 'products/roses.jpg', 'unit_price' => 250, 'quantity' => 2, 'addons_total' => 0, 'subtotal' => 500]);
        $line->addons()->create(['name' => 'بطاقة إهداء', 'unit_price' => 0, 'quantity' => 2, 'subtotal' => 0]);

        return $order;
    }

    public function test_the_details_screen_has_every_section(): void
    {
        $order = $this->order([
            'status' => Order::STATUS_PROCESSING, 'accepted_at' => '2026-10-03 10:05:00', 'preparing_at' => '2026-10-03 10:20:00',
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            // Header
            ->assertJsonPath('data.display_number', '#GFT-10254')
            ->assertJsonPath('data.placed_at_label', 'اليوم، 2:00 م')
            // Status
            ->assertJsonPath('data.current_status.key', 'processing')
            ->assertJsonPath('data.current_status.label', 'قيد التجهيز')
            ->assertJsonPath('data.current_status.description', 'يقوم المتجر بتجهيز طلبك الآن.')
            // Store
            ->assertJsonPath('data.store.store_name', 'عطوري')
            ->assertJsonPath('data.store.category', 'عطور ومبخرات')
            ->assertJsonPath('data.store.rating', 4.5)
            ->assertJsonPath('data.store.store_profile_id', $this->profile->id)
            ->assertJsonPath('data.store.can_visit', true)
            // Products
            ->assertJsonPath('data.products.0.name', 'باقة ورد فاخرة')
            ->assertJsonPath('data.products.0.quantity', 2)
            ->assertJsonPath('data.products.0.unit_price.formatted', '250.00 ر.س')
            ->assertJsonPath('data.products.0.total.amount', 500)
            ->assertJsonPath('data.products.0.addons.0.name', 'بطاقة إهداء')
            // Delivery
            ->assertJsonPath('data.delivery_info.address', 'الرياض، حي الياسمين، شارع الزهور، رقم 45')
            ->assertJsonPath('data.delivery_info.expected_time_label', 'اليوم، 10:30 ص - 11:00 ص')
            ->assertJsonPath('data.delivery_info.fee.amount', 25)
            // Payment summary
            ->assertJsonPath('data.payment_summary.subtotal.amount', 500)
            ->assertJsonPath('data.payment_summary.discount.amount', 50)
            ->assertJsonPath('data.payment_summary.tax.amount', 71.25)
            ->assertJsonPath('data.payment_summary.total.formatted', '546.25 ر.س')
            // Buttons
            ->assertJsonPath('data.actions.can_cancel', false)
            ->assertJsonPath('data.actions.can_contact_support', true)
            ->assertJsonPath('data.actions.support.phone', '920000000');

        $this->assertSame(
            ['pending' => 'completed', 'accepted' => 'completed', 'processing' => 'current', 'ready' => 'upcoming', 'out_for_delivery' => 'upcoming', 'arrived_to_dropoff' => 'upcoming', 'delivered' => 'upcoming'],
            collect($response->json('data.timeline'))->pluck('state', 'key')->all(),
        );
        $this->assertSame('قيد المراجعة', $response->json('data.timeline.0.label'));
        $this->assertSame('10:20 ص', $response->json('data.timeline.2.at_label'));
    }

    public function test_each_screen_section_has_a_ready_to_render_block(): void
    {
        $order = $this->order([
            'status' => Order::STATUS_PROCESSING, 'accepted_at' => '2026-10-03 10:05:00', 'preparing_at' => '2026-10-03 10:20:00',
        ]);

        $data = $this->actingAs($this->customer, 'sanctum')
            ->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/user/orders/{$order->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(['order_reference' => '#GFT-10254', 'placed_at_label' => 'اليوم، 2:00 م'], array_diff_key($data['header'], ['created_at' => 0]));
        $this->assertSame([false, false, true, false], array_slice(array_column($data['timeline'], 'is_current'), 0, 4));
        $this->assertSame('زيارة المتجر', $data['store']['visit_label']);
        $this->assertSame('الرياض، حي الياسمين', $data['store']['address']);
        $this->assertSame([false, '25.00 ر.س'], [$data['delivery_info']['is_free'], $data['delivery_info']['fee_label']]);

        $this->assertSame(
            [
                ['subtotal', 'المجموع الفرعي', 500],
                ['delivery_fee', 'رسوم التوصيل', 25],
                ['discount', 'الخصم', -50],
                ['tax', 'ضريبة القيمة المضافة (15%)', 71.25],
                ['total', 'الإجمالي', 546.25],
            ],
            array_map(fn ($line) => [$line['key'], $line['label'], $line['amount']['amount']], $data['payment_summary']['lines']),
        );

        $this->assertSame(
            [['cancel_order', 'إلغاء الطلب', false, null], ['contact_support', 'تواصل مع الدعم', true, null]],
            array_map(fn ($b) => [$b['key'], $b['label'], $b['enabled'], $b['endpoint'] ?? null], $data['actions']['buttons']),
        );
    }

    public function test_free_delivery_reads_free(): void
    {
        $order = $this->order(['status' => Order::STATUS_PENDING, 'delivery_fee' => 0]);

        $info = $this->actingAs($this->customer, 'sanctum')->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.delivery_info');

        $this->assertSame([true, 'مجاني'], [$info['is_free'], $info['fee_label']]);
    }

    public function test_a_delivered_order_completes_every_step(): void
    {
        $order = $this->order(['status' => Order::STATUS_DELIVERED, 'delivered_at' => '2026-10-03 11:00:00']);

        $states = collect($this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.timeline'))->pluck('state')->unique()->all();

        $this->assertSame(['completed'], array_values($states));
    }

    public function test_a_cancelled_order_keeps_the_steps_it_reached_and_ends_cancelled(): void
    {
        $order = $this->order(['status' => Order::STATUS_CANCELLED, 'accepted_at' => '2026-10-03 10:05:00', 'cancelled_at' => '2026-10-03 10:10:00']);

        $timeline = collect($this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.timeline'));

        $this->assertSame(['completed', 'completed', 'upcoming'], $timeline->take(3)->pluck('state')->all());
        $this->assertSame(['key' => 'cancelled', 'state' => 'current'], $timeline->last() ? ['key' => $timeline->last()['key'], 'state' => $timeline->last()['state']] : null);
    }

    public function test_user_orders_show_returns_the_same_details(): void
    {
        $order = $this->order(['status' => Order::STATUS_PENDING]);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/user/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order_type', 'standard')
            ->assertJsonPath('data.current_status.key', 'pending')
            ->assertJsonPath('data.timeline.0.state', 'current');
    }
}
