<?php

namespace Tests\Feature\Delivery;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The payment no longer sends the order anywhere: once paid, the shopper
 * sends it to the driver (POST /shopper/orders/{id}/status { status: send_to_driver }),
 * which creates it at Alshrouq (pickup at the shopper's pickup address, no
 * Alshrouq branch; drop-off at the customer's delivery address).
 */
class AlshrouqDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected const CREATE_URL = 'https://alshrouq.test/api/integration/tok-123/orders/create';

    protected User $customer;

    protected User $shopper;

    protected CustomOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['alshrouq.api_token' => 'tok-123', 'alshrouq.base_url' => 'https://alshrouq.test/api/integration', 'alshrouq.preparation_time' => 5]);
        Queue::fake([SendPushNotification::class]);

        $this->customer = User::factory()->customer()->create(['name' => 'Ahmed']);
        $this->shopper = User::factory()->shopper()->create();
        $pickup = UserAddress::query()->forceCreate([
            'user_id' => $this->shopper->id, 'location_name' => 'Mall', 'city' => 'Riyadh', 'district' => 'Olaya',
            'street' => 'Olaya St', 'building_number' => '1', 'latitude' => 24.7136, 'longitude' => 46.6753,
        ]);

        $this->order = CustomOrder::factory()->assignedTo($this->shopper)->create([
            'user_id' => $this->customer->id,
            'status' => CustomOrder::STATUS_WAITING_FOR_PAYMENT,
            'invoice_submitted_at' => now(), 'final_amount' => 87.39, 'total_amount' => 100.50,
            'pickup_address_id' => $pickup->id,
            'delivery_name' => 'Ahmed', 'delivery_phone' => '0501234567', 'delivery_address' => '123 Main St',
            'delivery_latitude' => 24.80, 'delivery_longitude' => 46.70, 'notes' => 'Ring twice',
        ]);
    }

    protected function created(array $overrides = []): array
    {
        return $overrides + [
            'order_id' => 609077, 'client_order_id' => $this->order->id, 'customer_name' => 'Ahmed', 'customer_phone' => '501234567',
            'branch_name' => 'Tawera', 'branch_id' => 33, 'fees' => '0', 'distance' => '0',
            'status_id' => 1, 'status_label' => 'Order created', 'value' => '100.5', 'cod' => '0',
            'payment_type_label' => 'Paid', 'payment_type' => 3, 'created_at' => '2025-04-21 1:17 PM',
        ];
    }

    protected function pay(): void
    {
        $this->assertTrue(app(PaymentService::class)->completeOrderPayment($this->order, ['result' => 'CAPTURED'], 'alrajhi', 'PAY-1'));
    }

    protected function sendToDriver(string $status = 'send_to_driver')
    {
        Sanctum::actingAs($this->shopper);

        return $this->postJson("/api/v1/shopper/orders/{$this->order->id}/status", ['status' => $status]);
    }

    public function test_the_payment_does_not_send_the_order_to_alshrouq(): void
    {
        Http::fake();

        $this->pay();

        Http::assertNothingSent();
        $order = $this->order->fresh();
        $this->assertSame(CustomOrder::STATUS_PAID, $order->status);
        $this->assertNull($order->delivery_reference);

        // The shopper app offers the next step
        Sanctum::actingAs($this->shopper);
        $this->getJson("/api/v1/shopper/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.actions.next_status', 'send_to_driver')
            ->assertJsonPath('data.delivery.full_address', '123 Main St')   // the address, not the tracking
            ->assertJsonPath('data.delivery_tracking', null);
    }

    public function test_the_shopper_sends_the_paid_order_to_the_driver(): void
    {
        Http::fake([self::CREATE_URL => Http::response($this->created(), 200)]);
        $this->pay();

        $this->sendToDriver()
            ->assertOk()
            ->assertJsonPath('message', __('custom_orders.sent_to_driver'))
            ->assertJsonPath('data.status', 'order_created')
            ->assertJsonPath('data.delivery_tracking.reference', '609077');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::CREATE_URL && $request->method() === 'POST' && $request->data() === [
            'branch_lat' => 24.7136, 'branch_lng' => 46.6753, 'branch_id' => null,
            'client_order_id' => (string) $this->order->id, 'value' => 100.5, 'payment_type' => 3, 'preparation_time' => 5,
            'customer_lat' => 24.8, 'customer_lng' => 46.7, 'customer_address' => '123 Main St',
            'customer_phone' => '501234567', 'customer_name' => 'Ahmed',
            'details' => $this->order->order_number.' - Ring twice',
        ]);

        $order = $this->order->fresh();
        $this->assertSame(CustomOrder::STATUS_ORDER_CREATED, $order->status);
        $this->assertSame('609077', $order->delivery_reference);
        $this->assertSame('Order created', $order->delivery_status);
        $this->assertNotNull($order->delivery_dispatched_at);
        $this->assertSame('Tawera', $order->delivery_data['branch_name']);

        // Customer and shopper are told it is with the delivery company
        $this->assertContains('notifications.custom_order_status_changed_title', AppNotification::query()->with('content')->where('notifiable_id', $this->customer->id)->get()->pluck('content.title_key')->all());
        $this->assertContains('notifications.custom_order_shopper_status_title', AppNotification::query()->with('content')->where('notifiable_id', $this->shopper->id)->get()->pluck('content.title_key')->all());

        // Pressed again (any alias): nothing new is created
        $this->sendToDriver('dispatch')->assertOk()->assertJsonPath('data.status', 'order_created');
        Http::assertSentCount(1);
    }

    public function test_a_refused_dispatch_keeps_the_order_paid_and_the_shopper_can_retry(): void
    {
        Http::fakeSequence(self::CREATE_URL)
            ->push(['message' => 'The customer phone format is invalid.'], 422)
            ->push($this->created(), 200);
        $this->pay();

        $this->sendToDriver()
            ->assertStatus(502)
            ->assertJsonPath('message', __('custom_orders.delivery_failed'))
            ->assertJsonPath('data.retryable', true);

        $order = $this->order->fresh();
        $this->assertSame([CustomOrder::STATUS_PAID, CustomOrder::PAYMENT_PAID, null], [$order->status, $order->payment_status, $order->delivery_reference]);
        $this->assertStringContainsString('The customer phone format is invalid.', $order->delivery_error);
        $this->assertStringNotContainsString('tok-123', $order->delivery_error);

        $this->sendToDriver()->assertOk()->assertJsonPath('data.status', 'order_created');
        $this->assertNull($this->order->fresh()->delivery_error);
    }

    public function test_alshrouq_unreachable_or_not_configured_is_a_clean_retryable_error(): void
    {
        Http::fake([self::CREATE_URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->pay();

        $this->sendToDriver()->assertStatus(502)->assertJsonPath('data.retryable', true);

        config(['alshrouq.api_token' => null]);
        $this->sendToDriver()->assertStatus(502);
        $this->assertSame(CustomOrder::STATUS_PAID, $this->order->fresh()->status);
    }

    public function test_only_a_paid_order_with_a_pickup_location_is_sent(): void
    {
        Http::fake();

        // Not paid yet
        $this->sendToDriver()->assertStatus(409)->assertJsonPath('data.current_status', 'waiting_for_payment');

        $this->pay();
        $this->order->pickupAddress->forceFill(['latitude' => null, 'longitude' => null])->save();
        $this->sendToDriver()->assertUnprocessable()->assertJsonPath('message', __('custom_orders.pickup_location_required'));

        // Another shopper's order
        Sanctum::actingAs(User::factory()->shopper()->create());
        $this->postJson("/api/v1/shopper/orders/{$this->order->id}/status", ['status' => 'send_to_driver'])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_support_can_retry_from_the_console(): void
    {
        Http::fake([self::CREATE_URL => Http::response($this->created(), 200)]);
        $this->pay();

        $this->artisan('custom-orders:dispatch-delivery', ['id' => $this->order->id])->assertSuccessful();

        $this->assertSame('609077', $this->order->fresh()->delivery_reference);
    }
}
