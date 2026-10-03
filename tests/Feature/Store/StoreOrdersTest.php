<?php

namespace Tests\Feature\Store;

use App\Models\NotificationContent;
use App\Models\Order;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Reference;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StoreOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed']);
        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'Rose Shop']);
    }

    protected function order(string $status, string $paymentStatus = Order::PAYMENT_PAID, array $attributes = [], int $lines = 1): Order
    {
        $order = Order::create($attributes + [
            'order_number' => Order::generateNumber(), 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_name' => 'Sara Ahmed', 'shipping_phone' => '966500000001',
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'delivery_fee' => 15,
            'tax_amount' => 17.25, 'discount_amount' => 0, 'total_amount' => 132.25,
            'status' => $status, 'payment_status' => $paymentStatus,
            'paid_at' => $paymentStatus === Order::PAYMENT_PAID ? now()->subMinutes(10) : null,
        ]);

        for ($i = 1; $i <= $lines; $i++) {
            $order->items()->create([
                'product_name' => "Product {$i}", 'product_image' => "products/{$i}.jpg",
                'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100,
            ]);
        }

        return $order;
    }

    public function test_orders_with_a_pending_payment_are_hidden_from_every_store_endpoint(): void
    {
        $paid = $this->order(Order::STATUS_PENDING);
        // Drifted row: status says paid-and-waiting but the payment never settled
        $unpaid = $this->order(Order::STATUS_PENDING, Order::PAYMENT_PENDING);
        $awaiting = $this->order(Order::STATUS_PENDING_PAYMENT, Order::PAYMENT_PENDING);

        $this->actingAs($this->store, 'sanctum');

        $ids = collect($this->getJson('/api/v1/store/orders')->assertOk()->json('data.items'))->pluck('id');
        $this->assertEquals([$paid->id], $ids->all());

        foreach ([$unpaid, $awaiting] as $hidden) {
            $this->getJson("/api/v1/store/orders/{$hidden->id}")->assertNotFound();
            $this->postJson("/api/v1/store/orders/{$hidden->id}/status", ['status' => 'accepted'])->assertNotFound();
            $this->postJson("/api/v1/store/orders/{$hidden->id}/status", ['status' => 'cancelled', 'reason' => 'Out of stock'])->assertNotFound();
        }

        $this->assertSame(Order::STATUS_PENDING, $unpaid->fresh()->status);
    }

    public function test_status_type_splits_current_and_previous_orders(): void
    {
        $new = $this->order(Order::STATUS_PENDING);
        $preparing = $this->order(Order::STATUS_PROCESSING);
        $delivered = $this->order(Order::STATUS_DELIVERED);
        $cancelled = $this->order(Order::STATUS_CANCELLED, Order::PAYMENT_REFUNDED);

        $this->actingAs($this->store, 'sanctum');

        $current = collect($this->getJson('/api/v1/store/orders?status_type=current')->assertOk()->json('data.items'))->pluck('id')->sort()->values();
        $this->assertEquals([$new->id, $preparing->id], $current->all());

        $previous = collect($this->getJson('/api/v1/store/orders?status_type=previous')->assertOk()->json('data.items'))->pluck('id')->sort()->values();
        $this->assertEquals([$delivered->id, $cancelled->id], $previous->all());

        // A status outside the chosen tab yields nothing rather than everything
        $this->getJson('/api/v1/store/orders?status_type=current&status=delivered')->assertOk()->assertJsonCount(0, 'data.items');

        $this->getJson('/api/v1/store/orders?status_type=archived')->assertUnprocessable();
    }

    public function test_search_matches_order_number_customer_name_and_id(): void
    {
        $match = $this->order(Order::STATUS_PENDING);
        $other = $this->order(Order::STATUS_PENDING, attributes: ['shipping_name' => 'Khalid']);

        $this->actingAs($this->store, 'sanctum');

        $this->getJson('/api/v1/store/orders?search='.$match->order_number)->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $match->id);
        $this->getJson('/api/v1/store/orders?search=Khalid')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $other->id);
        $this->getJson('/api/v1/store/orders?search='.$other->id)->assertJsonPath('data.items.0.id', $other->id);
    }

    public function test_list_card_has_exactly_the_card_fields(): void
    {
        $order = $this->order(Order::STATUS_DELIVERED, lines: 5);

        $card = $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/store/orders')->assertOk()->json('data.items.0');

        $this->assertSame([
            'id', 'order_reference', 'status_key', 'status_label', 'customer_name', 'customer_avatar', 'delivery_address',
            'time_ago', 'items_count_label', 'total_amount', 'items_preview', 'remaining_items_count', 'action_type', 'action_label',
        ], array_keys($card));

        $this->assertSame('#'.$order->order_number, $card['order_reference']);
        $this->assertSame('delivered', $card['status_key']);
        $this->assertSame('مكتمل', $card['status_label']);
        $this->assertSame('Sara Ahmed', $card['customer_name']);
        $this->assertSame('12, King Fahd Rd, Olaya, Riyadh', $card['delivery_address']);
        $this->assertSame('منذ 10 دقائق', $card['time_ago']);
        $this->assertSame('10 منتجات مطلوبة', $card['items_count_label']);   // 5 lines x 2
        $this->assertSame(132.25, $card['total_amount']['amount']);
        $this->assertCount(3, $card['items_preview']);
        $this->assertStringEndsWith('products/1.jpg', $card['items_preview'][0]);
        $this->assertSame(2, $card['remaining_items_count']);
        $this->assertSame('view_details', $card['action_type']);
        $this->assertSame('تفاصيل طلب', $card['action_label']);
    }

    public function test_items_count_label_follows_arabic_plurals(): void
    {
        foreach ([1 => 'منتج واحد مطلوب', 2 => 'منتجان مطلوبان', 3 => '3 منتجات مطلوبة', 12 => '12 منتجًا مطلوبًا'] as $count => $label) {
            app()->setLocale('ar');
            $this->assertSame($label, trans_choice('orders.items_count_label', $count, ['count' => $count]));
        }
    }

    public function test_details_include_items_contact_delivery_and_summary(): void
    {
        $order = $this->order(Order::STATUS_PENDING, lines: 2);

        $this->actingAs($this->store, 'sanctum')->getJson("/api/v1/store/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.badge', 'new')
            ->assertJsonPath('data.customer.phone', '966500000001')
            ->assertJsonPath('data.customer.call_url', 'tel:+966500000001')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Product 1')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', 50)
            ->assertJsonPath('data.totals.subtotal', 100)
            ->assertJsonPath('data.totals.delivery_fee', 15)
            ->assertJsonPath('data.totals.tax', 17.25)
            ->assertJsonPath('data.totals.discount', 0)
            ->assertJsonPath('data.totals.total', 132.25);
    }

    public function test_details_screen_sections(): void
    {
        $order = $this->order(Order::STATUS_PENDING, lines: 2, attributes: [
            'delivery_window_start' => now()->setTime(10, 30), 'delivery_window_end' => now()->setTime(11, 0),
        ]);

        $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/store/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.header_label', fn ($label) => str_starts_with($label, 'طلب #'.$order->order_number.' - اليوم، '))
            ->assertJsonPath('data.status_badge', ['key' => 'new', 'label' => 'طلب جديد'])
            ->assertJsonPath('data.customer.name', 'Sara Ahmed')
            ->assertJsonPath('data.customer.can_call', true)
            ->assertJsonCount(2, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Product 1')
            ->assertJsonPath('data.products.0.image', fn ($url) => str_ends_with($url, 'products/1.jpg'))
            ->assertJsonPath('data.products.0.unit_price.amount', 50)
            ->assertJsonPath('data.products.0.total.formatted', '100.00 ر.س')
            ->assertJsonPath('data.delivery_info.address', '12, King Fahd Rd, Olaya, Riyadh')
            ->assertJsonPath('data.delivery_info.expected_time_label', 'اليوم، 10:30 ص - 11:00 ص')
            ->assertJsonPath('data.delivery_info.fee.amount', 15)
            ->assertJsonPath('data.payment_summary.subtotal.amount', 100)
            ->assertJsonPath('data.payment_summary.delivery_fee.amount', 15)
            ->assertJsonPath('data.payment_summary.tax.amount', 17.25)
            ->assertJsonPath('data.payment_summary.discount.amount', 0)
            ->assertJsonPath('data.payment_summary.total.formatted', '132.25 ر.س');
    }

    public function test_status_endpoint_walks_the_full_flow_and_notifies_the_customer_each_step(): void
    {
        $order = $this->order(Order::STATUS_PENDING);
        $url = "/api/v1/store/orders/{$order->id}/status";

        $this->actingAs($this->store, 'sanctum');

        $this->getJson("/api/v1/store/orders/{$order->id}")->assertJsonPath('data.next_statuses', ['accepted', 'cancelled']);

        $steps = [
            'accepted' => [Order::STATUS_ACCEPTED, 'accepted', 'notifications.order_accepted_title'],
            'preparing' => [Order::STATUS_PROCESSING, 'preparing', 'notifications.order_processing_title'],
            'ready_for_pickup' => [Order::STATUS_READY, 'ready_for_pickup', 'notifications.order_ready_title'],
            'send_to_captain' => [Order::STATUS_OUT_FOR_DELIVERY, 'out_for_delivery', 'notifications.order_out_for_delivery_title'],
            'completed' => [Order::STATUS_DELIVERED, 'completed', 'notifications.order_delivered_title'],
        ];

        $previous = Order::STATUS_PENDING;

        foreach ($steps as $requested => [$status, $badge, $titleKey]) {
            $this->postJson($url, ['status' => $requested])
                ->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.badge', $badge);

            $notification = NotificationContent::query()->latest('id')->first();
            $this->assertSame($titleKey, $notification->title_key);
            $this->assertSame($status, $notification->data['status']);
            $this->assertSame($badge, $notification->data['badge']);
            $this->assertSame($previous, $notification->data['previous_status']);
            $this->assertTrue($notification->notifications()->where('notifiable_id', $this->customer->id)->exists());

            $previous = $status;
        }

        $this->assertSame(5, $order->statusHistories()->count());
        $this->postJson($url, ['status' => 'cancelled', 'reason' => 'Too late'])->assertUnprocessable()->assertJsonValidationErrors('status', 'data.errors');
    }

    public function test_status_endpoint_rejects_unknown_and_out_of_flow_statuses(): void
    {
        $order = $this->order(Order::STATUS_PENDING);
        $url = "/api/v1/store/orders/{$order->id}/status";

        $this->actingAs($this->store, 'sanctum');

        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('status', 'data.errors');
        $this->postJson($url, ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('status', 'data.errors');
        $this->postJson($url, ['status' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('status', 'data.errors');
        $this->postJson($url, ['status' => 'ready_for_pickup'])->assertUnprocessable()->assertJsonValidationErrors('status', 'data.errors');

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(0, NotificationContent::query()->count());
    }

    public function test_cancelling_requires_a_reason_and_sends_it_to_the_customer(): void
    {
        $order = $this->order(Order::STATUS_ACCEPTED);
        $url = "/api/v1/store/orders/{$order->id}/status";

        $this->actingAs($this->store, 'sanctum');

        $this->postJson($url, ['status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors('reason', 'data.errors');

        $this->postJson($url, ['status' => 'cancelled', 'reason' => 'Out of stock'])
            ->assertOk()
            ->assertJsonPath('data.badge', 'cancelled')
            ->assertJsonPath('data.next_statuses', []);

        $this->assertSame(Order::ACTOR_STORE, $order->fresh()->cancelled_by);

        $notification = NotificationContent::query()->latest('id')->first();
        $this->assertSame('notifications.order_cancelled_body', $notification->body_key);
        $this->assertSame('Out of stock', $notification->data['reason']);
        $this->assertSame(Order::STATUS_ACCEPTED, $notification->data['previous_status']);
    }

    public function test_send_to_captain_broadcasts_to_every_active_captain_without_assigning_one(): void
    {
        $captains = User::factory()->count(3)->captain()->create();
        $blocked = User::factory()->captain()->blocked()->create();
        $otherCustomer = User::factory()->customer()->create();

        $order = $this->order(Order::STATUS_READY);

        $this->actingAs($this->store, 'sanctum')
            ->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'send_to_captain', 'captain_id' => $captains[0]->id])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_OUT_FOR_DELIVERY);

        $this->assertNull($order->fresh()->captain_id);

        $broadcast = NotificationContent::query()->where('type', NotificationContent::TYPE_DELIVERY_REQUEST)->sole();
        $this->assertSame('notifications.delivery_request_title', $broadcast->title_key);
        $this->assertSame((string) $order->id, $broadcast->data['order_id']);
        $this->assertSame('captain_delivery_request', $broadcast->data['screen']);
        $this->assertStringContainsString('Olaya', $broadcast->renderBody('en'));

        $recipients = $broadcast->notifications()->pluck('notifiable_id')->sort()->values()->all();
        $this->assertSame($captains->pluck('id')->sort()->values()->all(), $recipients);
        $this->assertNotContains($blocked->id, $recipients);
        $this->assertNotContains($otherCustomer->id, $recipients);

        // The customer still gets their own "on the way" notification
        $this->assertTrue(NotificationContent::query()
            ->where('title_key', 'notifications.order_out_for_delivery_title')
            ->whereHas('notifications', fn ($q) => $q->where('notifiable_id', $this->customer->id))
            ->exists());
    }

    public function test_no_broadcast_for_other_status_changes(): void
    {
        User::factory()->count(2)->captain()->create();
        $order = $this->order(Order::STATUS_PENDING);

        $this->actingAs($this->store, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'accepted'])->assertOk();

        $this->assertSame(0, NotificationContent::query()->where('type', NotificationContent::TYPE_DELIVERY_REQUEST)->count());
    }

    public function test_send_to_captain_publishes_the_order_to_the_firebase_feed(): void
    {
        config(['services.captain_feed.enabled' => true, 'firebase.projects.app.database.url' => 'https://example.firebaseio.com']);

        StoreProfile::query()->where('user_id', $this->store->id)->sole()->saveMainLocation([
            'address' => 'Tahlia St, Riyadh', 'latitude' => 24.7000, 'longitude' => 46.6800, 'phone' => '966110000000',
        ]);
        $order = $this->order(Order::STATUS_READY, attributes: [
            'shipping_latitude' => 24.7136, 'shipping_longitude' => 46.6753, 'express_fee' => 5,
        ]);

        $written = null;
        $reference = Mockery::mock(Reference::class);
        $reference->shouldReceive('set')->once()->andReturnUsing(function (array $value) use (&$written, $reference) {
            $written = $value;

            return $reference;
        });
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('getReference')->once()->with("available_orders/{$order->id}")->andReturn($reference);
        $this->app->instance(Database::class, $database);

        $this->actingAs($this->store, 'sanctum')
            ->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'send_to_captain'])
            ->assertOk();

        $this->assertSame($order->id, $written['order_id']);
        $this->assertSame($order->order_number, $written['order_number']);
        $this->assertSame('available', $written['status']);
        $this->assertSame(20.0, $written['earnings']);
        $this->assertSame(132.25, $written['order_total']);
        $this->assertEqualsWithDelta(1.58, $written['distance_km'], 0.05);
        $this->assertSame('Rose Shop', $written['pickup']['store_name']);
        $this->assertSame('Tahlia St, Riyadh', $written['pickup']['address']);
        $this->assertSame('12, King Fahd Rd, Olaya, Riyadh', $written['dropoff']['address']);
        $this->assertSame(24.7136, $written['dropoff']['lat']);
        $this->assertSame(['.sv' => 'timestamp'], $written['published_at']);
        // Customer contact details stay off the shared feed
        $this->assertStringNotContainsString('966500000001', json_encode($written));
        $this->assertStringNotContainsString('Sara Ahmed', json_encode($written));
    }

    public function test_a_firebase_outage_does_not_block_sending_to_captains(): void
    {
        config(['services.captain_feed.enabled' => true, 'firebase.projects.app.database.url' => 'https://example.firebaseio.com']);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('getReference')->andThrow(new RuntimeException('Firebase unreachable'));
        $this->app->instance(Database::class, $database);

        $order = $this->order(Order::STATUS_READY);

        $this->actingAs($this->store, 'sanctum')
            ->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'send_to_captain'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_OUT_FOR_DELIVERY);

        $this->assertSame(Order::STATUS_OUT_FOR_DELIVERY, $order->fresh()->status);
    }

    public function test_completing_an_order_removes_it_from_the_firebase_feed(): void
    {
        config(['services.captain_feed.enabled' => true, 'firebase.projects.app.database.url' => 'https://example.firebaseio.com']);

        $order = $this->order(Order::STATUS_OUT_FOR_DELIVERY);

        $reference = Mockery::mock(Reference::class);
        $reference->shouldReceive('remove')->once()->andReturnSelf();
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('getReference')->once()->with("available_orders/{$order->id}")->andReturn($reference);
        $this->app->instance(Database::class, $database);

        $this->actingAs($this->store, 'sanctum')
            ->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();
    }

    public function test_old_accept_and_cancel_endpoints_are_gone(): void
    {
        $order = $this->order(Order::STATUS_PENDING);

        $this->actingAs($this->store, 'sanctum');

        $this->postJson("/api/v1/store/orders/{$order->id}/accept")->assertNotFound();
        $this->postJson("/api/v1/store/orders/{$order->id}/cancel", ['reason' => 'Out of stock'])->assertNotFound();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_other_stores_orders_are_not_found(): void
    {
        $order = $this->order(Order::STATUS_PENDING);
        $otherStore = User::factory()->storeOwner()->create();

        $this->actingAs($otherStore, 'sanctum')->getJson("/api/v1/store/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/store/orders/{$order->id}/status", ['status' => 'accepted'])->assertNotFound();
    }
}
