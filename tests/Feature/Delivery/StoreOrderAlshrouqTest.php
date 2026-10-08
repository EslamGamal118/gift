<?php

namespace Tests\Feature\Delivery;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Store orders delivered by Alshrouq: `send_to_captain` creates the order
 * there, its webhook moves the order on until `delivered`, and the customer
 * is told at every step.
 */
class StoreOrderAlshrouqTest extends TestCase
{
    use RefreshDatabase;

    protected const CREATE_URL = 'https://alshrouq.test/api/integration/tok-123/orders/create';

    protected const WEBHOOK = '/api/v1/webhooks/alshrouq?token=shh';

    protected User $customer;

    protected User $store;

    protected StoreProfile $profile;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'alshrouq.api_token' => 'tok-123', 'alshrouq.base_url' => 'https://alshrouq.test/api/integration',
            'alshrouq.webhook_secret' => 'shh', 'alshrouq.store_orders' => true,
        ]);
        Queue::fake([SendPushNotification::class]);

        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed']);
        $this->store = User::factory()->storeOwner()->create();
        $this->profile = StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'Rose Shop']);
        StoreBranch::factory()->create(['store_profile_id' => $this->profile->id, 'is_main' => true, 'latitude' => 24.7136, 'longitude' => 46.6753]);

        $this->order = Order::create([
            'order_number' => 'GFT-20261008-ABC123', 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_name' => 'Sara Ahmed', 'shipping_phone' => '0501234567',
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'shipping_latitude' => 24.80, 'shipping_longitude' => 46.70,
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'tax_amount' => 15, 'total_amount' => 115,
            'status' => Order::STATUS_READY, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now()->subHour(),
        ]);
        $this->order->items()->create(['product_name' => 'Red roses', 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100]);
    }

    protected function sendToCaptain()
    {
        return $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'en')
            ->postJson("/api/v1/store/orders/{$this->order->id}/status", ['status' => 'send_to_captain']);
    }

    protected function webhook(int $statusId, array $extra = [])
    {
        return $this->postJson(self::WEBHOOK, ['order_id' => 609077, 'client_order_id' => 'GFT-20261008-ABC123', 'status_id' => $statusId, 'status' => 'label '.$statusId] + $extra);
    }

    /**
     * @return list<string>
     */
    protected function customerNotifications(): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $this->customer->id)->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_send_to_captain_creates_the_order_at_alshrouq(): void
    {
        Http::fake([self::CREATE_URL => Http::response(['order_id' => 609077, 'client_order_id' => 'GFT-20261008-ABC123', 'status_id' => 1, 'status_label' => 'Order created'])]);

        $this->sendToCaptain()->assertOk()
            ->assertJsonPath('message', __('orders.dispatched_to_delivery', [], 'en'));

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::CREATE_URL && $request->data() === [
            'branch_lat' => 24.7136, 'branch_lng' => 46.6753, 'branch_id' => null,
            'client_order_id' => 'GFT-20261008-ABC123', 'value' => 115.0, 'payment_type' => 3, 'preparation_time' => 0,
            'customer_lat' => 24.8, 'customer_lng' => 46.7, 'customer_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'customer_phone' => '501234567', 'customer_name' => 'Sara Ahmed',
            'details' => 'GFT-20261008-ABC123 - 2x Red roses',
        ]);

        $order = $this->order->fresh();
        $this->assertSame([Order::STATUS_ORDER_CREATED, '609077', 'Order created'], [$order->status, $order->delivery_reference, $order->delivery_status]);
        $this->assertNotNull($order->delivery_updated_at);
        $this->assertSame(['ready', 'order_created', 'store'], [
            $order->statusHistories()->latest('id')->value('from_status'),
            $order->statusHistories()->latest('id')->value('to_status'),
            $order->statusHistories()->latest('id')->value('actor_type'),
        ]);
        $this->assertSame(['notifications.order_status_changed_title'], $this->customerNotifications());
        $this->assertSame('ready_for_pickup', $order->storeBadge());

        // Not twice: the order is no longer `ready` (out of flow)
        $this->sendToCaptain()->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_a_refused_or_unreachable_alshrouq_leaves_the_order_ready_with_a_422(): void
    {
        $calls = 0;
        Http::fake([self::CREATE_URL => function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['message' => 'The customer phone format is invalid.'], 422)
                : throw new \Illuminate\Http\Client\ConnectionException('timeout');
        }]);

        $this->sendToCaptain()->assertStatus(422)
            ->assertJsonPath('message', __('orders.delivery_failed', [], 'en'))
            ->assertJsonPath('data.retryable', true)
            ->assertJsonPath('data.reason', fn (string $reason) => str_contains($reason, 'The customer phone format is invalid.') && ! str_contains($reason, 'tok-123'));

        $this->sendToCaptain()->assertStatus(422);

        $order = $this->order->fresh();
        $this->assertSame([Order::STATUS_READY, null], [$order->status, $order->delivery_reference]);
        $this->assertSame(0, $order->statusHistories()->count());
        $this->assertSame([], $this->customerNotifications());
    }

    public function test_missing_locations_are_explained_before_calling_alshrouq(): void
    {
        Http::fake();

        $this->order->forceFill(['shipping_latitude' => null])->save();
        $this->sendToCaptain()->assertStatus(422)->assertJsonPath('message', __('orders.delivery_customer_location_required', [], 'en'));

        $this->order->forceFill(['shipping_latitude' => 24.8])->save();
        $this->profile->mainBranch->forceFill(['latitude' => null])->save();
        $this->sendToCaptain()->assertStatus(422)->assertJsonPath('message', __('orders.delivery_store_location_required', [], 'en'));

        Http::assertNothingSent();
    }

    public function test_without_alshrouq_the_order_still_goes_to_the_captains(): void
    {
        config(['alshrouq.api_token' => null]);
        Http::fake();

        $this->sendToCaptain()->assertOk()->assertJsonPath('message', __('orders.dispatched', [], 'en'));

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_OUT_FOR_DELIVERY, $this->order->fresh()->status);
    }

    public function test_the_webhook_moves_the_order_until_it_is_delivered(): void
    {
        Http::fake([self::CREATE_URL => Http::response(['order_id' => 609077, 'status_id' => 1, 'status_label' => 'Order created'])]);
        $this->sendToCaptain()->assertOk();

        $this->webhook(2)->assertOk()->assertJson(['applied' => true, 'order_type' => 'standard', 'status' => 'pending_driver_acceptance']);
        $this->webhook(17)->assertJson(['status' => 'driver_accepted']);
        $this->webhook(16)->assertJson(['status' => 'arrived_to_pickup']);
        $this->webhook(6)->assertJson(['status' => 'order_picked_up']);
        $this->assertNotNull($this->order->fresh()->dispatched_at);

        // The customer's timeline: picked up = on the way
        $timeline = collect($this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$this->order->id}")->assertOk()->json('data.timeline'));
        $this->assertSame('current', $timeline->firstWhere('key', 'out_for_delivery')['state']);

        $this->webhook(8)->assertJson(['status' => 'arrived_to_dropoff']);
        $timeline = collect($this->getJson("/api/v1/orders/{$this->order->id}")->json('data.timeline'));
        $this->assertSame(['completed', 'current'], [$timeline->firstWhere('key', 'out_for_delivery')['state'], $timeline->firstWhere('key', 'arrived_to_dropoff')['state']]);

        // "Order delivered" ends a store order `delivered`
        $this->webhook(9)->assertJson(['applied' => true, 'status' => 'delivered']);
        $order = $this->order->fresh();
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('completed', $order->storeBadge());
        $this->webhook(10)->assertJson(['applied' => false, 'status' => 'delivered']);

        $this->assertSame([
            'notifications.order_status_changed_title',         // sent to the delivery company
            'notifications.order_status_changed_title',         // waiting for a driver
            'notifications.order_driver_accepted_title',
            'notifications.order_status_changed_title',         // driver at the store
            'notifications.order_out_for_delivery_title',       // picked up
            'notifications.order_arrived_title',
            'notifications.order_delivered_title',
        ], $this->customerNotifications());

        $this->assertSame(
            ['ready', 'order_created', 'pending_driver_acceptance', 'driver_accepted', 'arrived_to_pickup', 'order_picked_up', 'arrived_to_dropoff', 'delivered'],
            [$order->statusHistories()->orderBy('id')->value('from_status'), ...$order->statusHistories()->orderBy('id')->pluck('to_status')->all()],
        );
    }

    public function test_late_updates_are_ignored_and_a_delivery_cancellation_is_recorded(): void
    {
        $this->order->forceFill(['status' => Order::STATUS_ORDER_PICKED_UP, 'delivery_reference' => '609077'])->save();

        $this->webhook(17)->assertJson(['applied' => false, 'status' => 'order_picked_up']);
        $this->webhook(21)->assertJson(['applied' => true, 'status' => 'cancellation_processing']);
        $this->webhook(10, ['cancellation_reason' => 'Customer unreachable'])->assertJson(['applied' => true, 'status' => 'cancelled']);

        $order = $this->order->fresh();
        $this->assertSame([Order::ACTOR_DELIVERY, 'Customer unreachable'], [$order->cancelled_by, $order->cancellation_reason]);
        $this->assertSame(['notifications.order_cancellation_processing_title', 'notifications.order_delivery_cancelled_title'], $this->customerNotifications());
    }

    public function test_an_order_not_handed_to_alshrouq_is_never_moved(): void
    {
        $this->webhook(6)->assertOk()->assertJson(['applied' => false, 'status' => 'ready']);
        $this->assertSame(Order::STATUS_READY, $this->order->fresh()->status);
    }
}
