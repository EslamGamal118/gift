<?php

namespace Tests\Feature\Delivery;

use App\Jobs\RefundCustomOrderPayment;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/webhooks/alshrouq: Alshrouq moves paid custom orders through
 * the delivery statuses (by status id) until delivered (completed); the
 * customer and the shopper are told about every change.
 */
class AlshrouqWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/api/v1/webhooks/alshrouq?token=shh';

    protected const DRIVER = [
        'id' => 5, 'name' => 'Saad Driver', 'phone' => '566278832', 'status' => 'Busy',
        'tracking_url' => 'https://www.google.com/maps?q=24.7127,46.6765',
        'location' => ['lat' => '24.712730638999666', 'lng' => '46.67650162439996'],
    ];

    protected User $customer;

    protected User $shopper;

    protected CustomOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['alshrouq.webhook_secret' => 'shh', 'alshrouq.api_token' => null]);
        Queue::fake([SendPushNotification::class, RefundCustomOrderPayment::class]);

        $this->customer = User::factory()->customer()->create();
        $this->shopper = User::factory()->shopper()->create();
        $this->order = CustomOrder::factory()->assignedTo($this->shopper)->paid()
            ->create(['user_id' => $this->customer->id, 'delivery_reference' => '85001']);
    }

    protected function update(int $statusId, array $extra = [], string $url = self::URL)
    {
        return $this->postJson($url, [
            'order_id' => 85001, 'client_order_id' => (string) $this->order->id, 'status_id' => $statusId,
            'status' => 'Alshrouq label', 'created_at' => '2026-01-04 20:28:01',
        ] + $extra);
    }

    /**
     * @return list<string>
     */
    protected function notificationsOf(User $user): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $user->id)->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_the_secret_is_required(): void
    {
        $this->update(1, url: '/api/v1/webhooks/alshrouq?token=wrong')->assertUnauthorized();
        $this->update(1, url: '/api/v1/webhooks/alshrouq')->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/alshrouq', ['order_id' => 85001, 'status_id' => 1], ['X-Alshrouq-Secret' => 'shh'])->assertOk();

        config(['alshrouq.webhook_secret' => null]);
        $this->update(2, url: '/api/v1/webhooks/alshrouq?token=')->assertUnauthorized();
    }

    public function test_status_ids_move_the_paid_order_until_it_is_delivered(): void
    {
        $this->update(1)->assertOk()->assertJson(['applied' => true, 'status' => 'order_created']);
        $this->update(2)->assertJson(['status' => 'pending_driver_acceptance']);
        $this->update(17, ['driver' => self::DRIVER])->assertJson(['applied' => true, 'status' => 'driver_accepted']);

        $order = $this->order->fresh();
        $this->assertSame('Saad Driver', $order->delivery_driver['name']);
        $this->assertSame('566278832', $order->delivery_driver['phone']);
        $this->assertSame(self::DRIVER['tracking_url'], $order->delivery_driver['tracking_url']);
        $this->assertEqualsWithDelta(24.7127306, $order->delivery_driver['location']['lat'], 0.00001);

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/custom-orders/{$this->order->id}")->assertOk()
            ->assertJsonPath('data.status', 'driver_accepted')
            ->assertJsonPath('data.status_label', 'Driver accepted the order')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.delivery_tracking.reference', '85001')
            ->assertJsonPath('data.delivery_tracking.driver.name', 'Saad Driver')
            ->assertJsonPath('data.delivery_tracking.driver.tracking_url', self::DRIVER['tracking_url']);

        foreach ([4 => 'pending_order_preparation', 16 => 'arrived_to_pickup', 6 => 'order_picked_up', 8 => 'arrived_to_dropoff'] as $id => $status) {
            $this->update($id)->assertJson(['applied' => true, 'status' => $status]);
        }
        $this->assertNull($this->order->fresh()->completed_at);

        $this->update(9)->assertJson(['applied' => true, 'status' => 'completed']);

        $order = $this->order->fresh();
        $this->assertNotNull($order->completed_at);
        $this->assertSame(CustomOrder::PAYMENT_PAID, $order->payment_status);

        // Every change reached both the customer and the shopper
        $this->assertSame([
            'notifications.custom_order_status_changed_title',    // order created
            'notifications.custom_order_status_changed_title',    // pending driver acceptance
            'notifications.custom_order_driver_accepted_title',
            'notifications.custom_order_status_changed_title',    // pending order preparation
            'notifications.custom_order_status_changed_title',    // arrived to pickup
            'notifications.custom_order_picked_up_title',
            'notifications.custom_order_arrived_title',
            'notifications.custom_order_completed_title',
        ], $this->notificationsOf($this->customer));
        $this->assertSame(array_fill(0, 8, 'notifications.custom_order_shopper_status_title'), $this->notificationsOf($this->shopper));

        $note = AppNotification::query()->with('content')->where('notifiable_id', $this->shopper->id)->latest('id')->first()->content;
        $this->assertStringContainsString('تم التوصيل', $note->renderBody('ar'));
        $this->assertStringContainsString('Delivered', $note->renderBody('en'));

        // Delivered is final
        $this->update(10)->assertOk()->assertJson(['applied' => false, 'status' => 'completed']);
    }

    public function test_late_updates_never_move_the_order_back_but_a_dropped_driver_does(): void
    {
        $this->update(16);
        $this->update(17)->assertJson(['applied' => false, 'status' => 'arrived_to_pickup']);
        $this->update(16, ['driver' => self::DRIVER])->assertJson(['applied' => false]);   // repeated, still tracked
        $this->assertSame('Saad Driver', $this->order->fresh()->delivery_driver['name']);

        // The driver dropped it before pickup: waiting for a driver again
        $this->update(2)->assertJson(['applied' => true, 'status' => 'pending_driver_acceptance']);

        $this->update(6);
        $this->update(2)->assertJson(['applied' => false, 'status' => 'order_picked_up']);
    }

    public function test_an_unpaid_order_is_not_moved(): void
    {
        $this->order->forceFill(['status' => CustomOrder::STATUS_WAITING_FOR_PAYMENT, 'payment_status' => CustomOrder::PAYMENT_PENDING, 'paid_at' => null])->save();

        $this->update(1)->assertOk()->assertJson(['applied' => false, 'status' => 'waiting_for_payment']);
    }

    public function test_a_delivery_cancellation_is_never_refunded_automatically(): void
    {
        $this->update(17);
        $this->update(21)->assertJson(['status' => 'cancellation_processing']);
        $this->update(10, ['cancellation_reason' => 'Customer unreachable'])->assertJson(['applied' => true, 'status' => 'cancelled']);

        $order = $this->order->fresh();
        $this->assertSame(CustomOrder::ACTOR_DELIVERY, $order->cancelled_by);
        $this->assertSame('Customer unreachable', $order->cancellation_reason);
        $this->assertSame(CustomOrder::PAYMENT_PAID, $order->payment_status);
        Queue::assertNotPushed(RefundCustomOrderPayment::class);
        $this->assertSame([
            'notifications.custom_order_driver_accepted_title',
            'notifications.custom_order_cancellation_processing_title',
            'notifications.custom_order_delivery_cancelled_title',
        ], $this->notificationsOf($this->customer));
        $this->assertCount(3, $this->notificationsOf($this->shopper));
    }

    public function test_a_cancellation_being_processed_can_resume_the_delivery(): void
    {
        $this->update(21);
        $this->update(6)->assertJson(['applied' => true, 'status' => 'order_picked_up']);
    }

    public function test_the_order_is_found_by_its_client_id_only_while_not_linked_to_another_alshrouq_order(): void
    {
        $this->order->forceFill(['delivery_reference' => null])->save();
        $this->update(1)->assertJson(['applied' => true]);
        $this->assertSame('85001', $this->order->fresh()->delivery_reference);

        // Same client id, different Alshrouq order: not ours
        $this->postJson(self::URL, ['order_id' => 99999, 'client_order_id' => (string) $this->order->id, 'status_id' => 9])
            ->assertOk()->assertJson(['applied' => false]);
        $this->assertSame(CustomOrder::STATUS_ORDER_CREATED, $this->order->fresh()->status);
    }

    public function test_unknown_orders_and_statuses_are_acknowledged_without_changes(): void
    {
        $this->postJson(self::URL, ['order_id' => 1, 'client_order_id' => '999999', 'status_id' => 1])->assertOk()->assertJson(['applied' => false]);
        $this->update(77, ['status' => 'Teleported'])->assertOk()->assertJson(['applied' => false]);
        $this->postJson(self::URL, ['status_id' => 1])->assertUnprocessable();

        // The name is a fallback when there is no id
        $this->postJson(self::URL, ['order_id' => 85001, 'status' => 'Order picked up'])->assertJson(['applied' => true, 'status' => 'order_picked_up']);
    }
}
