<?php

namespace Tests\Feature\Store;

use App\Models\AppNotification;
use App\Models\Order;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoreDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 15:00 in Riyadh (12:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));

        $this->customer = User::factory()->customer()->create(['name' => 'أحمد عبد الله', 'avatar' => 'https://example.test/a.png']);
        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function order(string $createdAt, string $status, array $attributes = []): Order
    {
        $order = new Order;
        $order->forceFill($attributes + [
            'order_number' => Order::generateNumber(), 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_name' => 'أحمد عبد الله', 'shipping_city' => 'الرياض', 'shipping_district' => 'العليا', 'shipping_street' => 'طريق الملك فهد',
            'shipping_building_number' => '12', 'shipping_address' => '12, طريق الملك فهد, العليا, الرياض',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 100,
            'status' => $status, 'payment_status' => Order::PAYMENT_PAID,
            'created_at' => Carbon::parse($createdAt, 'UTC'),
        ])->save();

        $order->items()->create(['product_name' => 'ورد أحمر', 'product_image' => 'products/rose.jpg', 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100]);

        return $order;
    }

    protected function seedOrders(): void
    {
        $accepted = ['accepted_at' => now()];

        // Today (since 21:00 UTC yesterday = midnight Riyadh)
        $this->order('2026-09-23 06:00:00', Order::STATUS_PENDING);
        $this->order('2026-09-23 07:00:00', Order::STATUS_ACCEPTED, $accepted);
        $this->order('2026-09-22 22:00:00', Order::STATUS_DELIVERED, $accepted);

        // Same weekday last week: two before "now minus a week", one after it
        $this->order('2026-09-16 08:00:00', Order::STATUS_DELIVERED, $accepted);
        $this->order('2026-09-16 11:00:00', Order::STATUS_DELIVERED, $accepted);
        $this->order('2026-09-16 18:00:00', Order::STATUS_DELIVERED, $accepted);

        // Rejected by the store vs. cancelled by the customer (only the first hurts the rate)
        $this->order('2026-09-13 10:00:00', Order::STATUS_CANCELLED, ['cancelled_by' => Order::ACTOR_STORE]);
        $this->order('2026-09-12 10:00:00', Order::STATUS_CANCELLED, ['cancelled_by' => Order::ACTOR_CUSTOMER]);

        // Older than the 30-day period: left out of every period counter
        $this->order('2026-07-01 10:00:00', Order::STATUS_DELIVERED, $accepted);

        // Never counted: unpaid, drifted unpaid, refunded
        $this->order('2026-09-23 11:00:00', Order::STATUS_PENDING_PAYMENT, ['payment_status' => Order::PAYMENT_PENDING]);
        $this->order('2026-09-23 11:30:00', Order::STATUS_PENDING, ['payment_status' => Order::PAYMENT_PENDING]);
        $this->order('2026-09-23 11:45:00', Order::STATUS_CANCELLED, ['payment_status' => Order::PAYMENT_REFUNDED, 'cancelled_by' => Order::ACTOR_STORE]);
    }

    public function test_stats_count_only_paid_orders(): void
    {
        $this->seedOrders();

        $data = $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'ar')->getJson('/api/v1/store/dashboard')->assertOk()->json('data');

        $this->assertSame('month', $data['period']);
        $this->assertSame(['period', 'notification_bell_icon', 'live_performance', 'counters', 'latest_orders'], array_keys($data));

        // 3 today vs 2 at the same time last week
        $this->assertSame(3, $data['live_performance']['today_orders_count']);
        $this->assertSame('+50% مقارنة بالأسبوع الماضي', $data['live_performance']['growth_percentage']);
        $this->assertEquals(50, $data['live_performance']['growth_value']);
        $this->assertSame('up', $data['live_performance']['trend']);

        $this->assertSame(1, $data['counters']['new_orders_count']);
        $this->assertSame(4, $data['counters']['completed_orders_count']);
        // 5 accepted vs 1 rejected by the store; the customer cancellation is ignored
        $this->assertSame('83.3%', $data['counters']['acceptance_rate']);
        // 3 today + 3 last week; cancellations and the July order excluded
        $this->assertEquals(600, $data['counters']['total_earnings']['amount']);
        $this->assertSame('SAR', $data['counters']['total_earnings']['currency']);
        $this->assertSame('600.00 ر.س', $data['counters']['total_earnings']['formatted']);
    }

    public function test_period_narrows_the_counters(): void
    {
        $this->seedOrders();

        $this->actingAs($this->store, 'sanctum')->getJson('/api/v1/store/dashboard?period=today')
            ->assertOk()
            ->assertJsonPath('data.counters.completed_orders_count', 1)
            ->assertJsonPath('data.counters.acceptance_rate', '100%')
            ->assertJsonPath('data.counters.total_earnings.amount', 300)
            ->assertJsonPath('data.counters.new_orders_count', 1);

        $this->getJson('/api/v1/store/dashboard?period=year')->assertUnprocessable();
    }

    public function test_recent_orders_are_the_latest_paid_ones_as_cards(): void
    {
        $this->seedOrders();

        $orders = $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'ar')->getJson('/api/v1/store/dashboard?orders_limit=3')->assertOk()->json('data.latest_orders');

        // Newest by placement time: the 11:45 refunded and 11:00/11:30 unpaid orders are skipped
        $expected = Order::query()->forStore($this->store->id)->paid()->latest('created_at')->latest('id')->limit(3)->pluck('id')->all();
        $this->assertSame(
            ['2026-09-23 07:00:00', '2026-09-23 06:00:00', '2026-09-22 22:00:00'],
            Order::query()->whereKey($expected)->orderByDesc('created_at')->pluck('created_at')->map->format('Y-m-d H:i:s')->all(),
        );
        $this->assertSame($expected, array_column($orders, 'id'));

        // The same card as GET /store/orders
        $card = $orders[0];
        $this->assertSame([
            'id', 'order_reference', 'status_key', 'status_label', 'customer_name', 'customer_avatar', 'delivery_address',
            'time_ago', 'items_count_label', 'total_amount', 'items_preview', 'remaining_items_count', 'action_type', 'action_label',
        ], array_keys($card));
        $this->assertStringStartsWith('#', $card['order_reference']);
        $this->assertSame('أحمد عبد الله', $card['customer_name']);
        $this->assertSame('https://example.test/a.png', $card['customer_avatar']);
        $this->assertSame('12, طريق الملك فهد, العليا, الرياض', $card['delivery_address']);
        $this->assertStringStartsWith('منذ', $card['time_ago']);
        $this->assertSame('منتجان مطلوبان', $card['items_count_label']);
        $this->assertCount(1, $card['items_preview']);
        $this->assertSame(0, $card['remaining_items_count']);
        $this->assertSame('view_details', $card['action_type']);
        $this->assertSame('تفاصيل طلب', $card['action_label']);
    }

    public function test_empty_store_gets_zeroes_and_no_rate(): void
    {
        $this->actingAs($this->store, 'sanctum')->getJson('/api/v1/store/dashboard')
            ->assertOk()
            ->assertJsonPath('data.live_performance.today_orders_count', 0)
            ->assertJsonPath('data.live_performance.growth_value', 0)
            ->assertJsonPath('data.live_performance.trend', 'flat')
            ->assertJsonPath('data.counters.acceptance_rate', null)
            ->assertJsonPath('data.counters.total_earnings.amount', 0)
            ->assertJsonCount(0, 'data.latest_orders')
            ->assertJsonPath('data.notification_bell_icon.has_unread', false)
            ->assertJsonPath('data.notification_bell_icon.unread_count', 0);
    }

    public function test_notification_bell_counts_only_the_stores_unread_notifications(): void
    {
        $service = app(NotificationService::class);

        foreach (['THD-1', 'THD-2', 'THD-3'] as $number) {
            $service->send($this->store, 'notifications.new_order_title', 'notifications.new_order_body',
                ['order_number' => $number], ['order_number' => $number, 'customer_name' => 'أحمد', 'total' => '400.00', 'currency' => 'SAR'], push: false);
        }
        $service->send($this->customer, 'notifications.new_order_title', 'notifications.new_order_body', push: false);

        AppNotification::query()->where('notifiable_id', $this->store->id)->oldest('id')->first()->markAsRead();

        $data = $this->actingAs($this->store, 'sanctum')->getJson('/api/v1/store/dashboard')->assertOk()->json('data');

        // The bell is just the indicator: no notification list on the dashboard
        $this->assertSame(['has_unread' => true, 'unread_count' => 2], $data['notification_bell_icon']);
    }

    public function test_only_stores_can_open_the_dashboard(): void
    {
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/store/dashboard')->assertForbidden();
    }
}
