<?php

namespace Tests\Feature\Shopper;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use App\Models\CustomOrderItem;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopperOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $shopper;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopper  = User::factory()->shopper()->create(['name' => 'Huda']);
        $this->customer = User::factory()->customer()->create(['locale' => 'ar']);

        Queue::fake([SendPushNotification::class]);
        config(['checkout.tax.rate' => 0]);   // VAT is covered by CustomOrderPaymentTest
    }

    protected function order(string $status = CustomOrder::STATUS_PENDING, ?User $shopper = null): CustomOrder
    {
        $order = CustomOrder::factory()->assignedTo($shopper ?? $this->shopper)->create(['user_id' => $this->customer->id, 'status' => $status]);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id, 'product_name' => 'Oud perfume 100ml']);

        return $order;
    }

    protected function moveTo(CustomOrder $order, string $status, array $extra = [])
    {
        Sanctum::actingAs($this->shopper);

        return $this->postJson("/api/v1/shopper/orders/{$order->id}/status", ['status' => $status] + $extra);
    }

    /**
     * Title keys of the customer's notifications, oldest first.
     *
     * @return list<string>
     */
    protected function customerNotifications(): array
    {
        return AppNotification::query()->with('content')
            ->where('notifiable_id', $this->customer->id)
            ->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_the_full_workflow_stamps_each_step_and_notifies_the_customer(): void
    {
        $order = $this->order();

        $this->moveTo($order, 'accepted')->assertOk()
            ->assertJsonPath('message', __('custom_orders.status_updated'))
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.status_badge.key', 'accepted');
        $this->assertNotNull($order->fresh()->accepted_at);

        $this->moveTo($order, 'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNotNull($order->fresh()->started_at);

        $this->moveTo($order, 'purchased', ['final_amount' => 245.5])->assertOk()   // alias of completed
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.tab', 'active')   // purchased, the customer pays next
            ->assertJsonPath('data.final_amount', null);   // never taken from the client; no prices yet
        $this->assertNotNull($order->fresh()->purchased_at);

        $this->assertSame([
            'notifications.custom_order_accepted_title',
            'notifications.custom_order_in_progress_title',
            'notifications.custom_order_waiting_for_payment_title',
        ], $this->customerNotifications());
        Queue::assertPushed(SendPushNotification::class, 3);

        // Rendered for the customer, with the deep link and previous status
        $content = AppNotification::query()->where('notifiable_id', $this->customer->id)->latest('id')->first()->content;
        $this->assertSame('custom_order_details', $content->data['screen']);
        $this->assertSame('in_progress', $content->data['previous_status']);
        $this->assertSame('قام المتسوق Huda بقبول طلبك '.$order->order_number.'.', __('notifications.custom_order_accepted_body', ['shopper_name' => 'Huda', 'order_number' => $order->order_number], 'ar'));
    }

    public function test_steps_cannot_be_skipped_or_repeated(): void
    {
        $order = $this->order();

        $this->moveTo($order, 'in_progress')->assertStatus(409)->assertJsonPath('data.current_status', 'pending');
        $this->moveTo($order, 'completed')->assertStatus(409);

        $this->moveTo($order, 'accepted')->assertOk();
        $this->moveTo($order, 'accepted')->assertStatus(409);

        $this->moveTo($order, 'draft')->assertUnprocessable();
        $this->moveTo($order, 'pending')->assertUnprocessable();

        $this->assertSame('accepted', $order->fresh()->status);
        $this->assertSame(['notifications.custom_order_accepted_title'], $this->customerNotifications());
    }

    public function test_shopper_rejects_a_new_order_with_a_reason_and_the_customer_is_told_why(): void
    {
        $order = $this->order();

        $this->moveTo($order, 'reject', ['cancellation_reason' => '  المنتج غير متوفر في منطقتي  '])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_badge.key', 'cancelled')
            ->assertJsonPath('data.tab', 'history');

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(CustomOrder::ACTOR_SHOPPER, $order->cancelled_by);
        $this->assertSame('المنتج غير متوفر في منطقتي', $order->cancellation_reason);
        $this->assertNull($order->accepted_at);

        $this->assertSame(['notifications.custom_order_declined_title'], $this->customerNotifications());
        Queue::assertPushed(SendPushNotification::class, 1);
        $content = AppNotification::query()->where('notifiable_id', $this->customer->id)->sole()->content;
        $this->assertSame('المنتج غير متوفر في منطقتي', $content->body_params['reason']);
        $this->assertSame('المنتج غير متوفر في منطقتي', $content->data['reason']);
        $this->assertSame('pending', $content->data['previous_status']);
        $this->assertSame(
            'اعتذر المتسوق Huda عن تنفيذ طلبك '.$order->order_number.'. السبب: المنتج غير متوفر في منطقتي',
            __($content->body_key, $content->body_params, 'ar'),
        );
    }

    public function test_shopper_can_give_up_an_accepted_or_in_progress_order_but_not_a_finished_one(): void
    {
        foreach (['cancel', 'canceled', 'Decline', 'cancelled'] as $i => $alias) {
            $order = $this->order($i % 2 ? CustomOrder::STATUS_IN_PROGRESS : CustomOrder::STATUS_ACCEPTED);
            $this->moveTo($order, $alias, ['cancellation_reason' => 'Emergency, cannot continue'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        }

        foreach ([CustomOrder::STATUS_COMPLETED, CustomOrder::STATUS_CANCELLED] as $status) {
            $this->moveTo($this->order($status), 'rejected', ['cancellation_reason' => 'Too late'])
                ->assertStatus(409)
                ->assertJsonPath('data.current_status', $status);
        }

        $this->assertCount(4, $this->customerNotifications());
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $order = $this->order();

        $this->moveTo($order, 'reject')->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason', 'data.errors');
        $this->moveTo($order, 'reject', ['cancellation_reason' => '  '])->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason', 'data.errors');
        $this->moveTo($order, 'reject', ['cancellation_reason' => str_repeat('x', 501)])->assertUnprocessable();

        // The reason is ignored for other steps
        $this->moveTo($order, 'accepted', ['cancellation_reason' => 'not used'])->assertOk();
        $this->assertNull($order->fresh()->cancellation_reason);

        $this->assertSame('accepted', $order->fresh()->status);
    }

    public function test_only_the_assigned_shopper_can_act_on_a_confirmed_order(): void
    {
        $others = $this->order(CustomOrder::STATUS_PENDING, User::factory()->shopper()->create());
        $draft  = $this->order(CustomOrder::STATUS_DRAFT);

        $this->moveTo($others, 'accepted')->assertNotFound();
        $this->moveTo($draft, 'accepted')->assertNotFound();
        $this->postJson('/api/v1/shopper/orders/999999/status', ['status' => 'accepted'])->assertNotFound();

        $mine = $this->order();
        $this->postJson("/api/v1/shopper/orders/{$mine->id}/status", ['status' => 'accepted'])->assertOk();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/shopper/orders/{$mine->id}/status", ['status' => 'in_progress'])->assertForbidden();

        $this->assertSame(CustomOrder::STATUS_PENDING, $others->fresh()->status);
    }

    public function test_shopper_suggests_an_alternative_with_an_image_and_the_customer_is_asked_to_review_it(): void
    {
        Storage::fake('public');
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $item  = $order->items()->first();

        Sanctum::actingAs($this->shopper);
        $response = $this->post("/api/v1/shopper/orders/{$order->id}/alternatives", [
            'item_id'      => $item->id,
            'product_name' => 'Oud perfume 50ml',
            'price'        => 189.9,
            'reason'       => 'عدم توفر الحجم المطلوب',
            'image'        => UploadedFile::fake()->image('alt.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('message', __('custom_orders.alternative_sent'))
            ->assertJsonPath('data.item_id', $item->id)
            ->assertJsonPath('data.item_name', 'Oud perfume 100ml')
            ->assertJsonPath('data.product_name', 'Oud perfume 50ml')
            ->assertJsonPath('data.price.amount', 189.9)
            ->assertJsonPath('data.status', 'pending')
            // The order now waits for the customer's answer
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.order.status', 'waiting_for_alternative')
            ->assertJsonPath('data.order.status_label', __('custom_orders.statuses.waiting_for_alternative'));
        $this->assertSame(CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE, $order->fresh()->status);

        $alternative = CustomOrderAlternative::query()->sole();
        Storage::disk('public')->assertExists($alternative->image_path);
        $this->assertStringContainsString("custom-orders/{$order->id}/alternatives/", $alternative->image_path);

        // Customer: in-app + push, priced in their language, deep link to the suggestion
        $this->assertSame(['notifications.custom_order_alternative_title'], $this->customerNotifications());
        Queue::assertPushed(SendPushNotification::class, 1);
        $content = AppNotification::query()->where('notifiable_id', $this->customer->id)->sole()->content;
        $this->assertSame('custom_order_alternative', $content->data['screen']);
        $this->assertSame((string) $alternative->id, $content->data['alternative_id']);
        $this->assertSame('189.90 ر.س', $content->body_params['price']);

        // The customer sees it on the order details for review
        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/custom-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.alternatives.0.id', $alternative->id)
            ->assertJsonPath('data.items.0.alternatives.0.reason', 'عدم توفر الحجم المطلوب');
    }

    public function test_alternatives_are_validated_and_only_allowed_while_shopping(): void
    {
        Storage::fake('public');
        $order   = $this->order(CustomOrder::STATUS_ACCEPTED);
        $foreign = CustomOrderItem::factory()->create();   // item of another order
        Sanctum::actingAs($this->shopper);

        $url = "/api/v1/shopper/orders/{$order->id}/alternatives";

        $this->postJson($url, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['item_id', 'product_name', 'price', 'reason'], 'data.errors');
        $this->postJson($url, ['item_id' => $foreign->id, 'product_name' => 'X perfume', 'price' => 10, 'reason' => 'Out of stock'])
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.item_id.0', __('custom_orders.item_not_in_order'));
        $this->post($url, [
            'item_id' => $order->items()->first()->id, 'product_name' => 'X perfume', 'price' => 10, 'reason' => 'Out of stock',
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image', 'data.errors');

        // Not while waiting for acceptance, nor once finished; the uploaded image is not kept
        foreach ([CustomOrder::STATUS_PENDING, CustomOrder::STATUS_COMPLETED] as $status) {
            $closed = $this->order($status);
            $this->post("/api/v1/shopper/orders/{$closed->id}/alternatives", [
                'item_id' => $closed->items()->first()->id, 'product_name' => 'X perfume', 'price' => 10, 'reason' => 'Out of stock',
                'image' => UploadedFile::fake()->image('alt.jpg'),
            ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('data.current_status', $status);
        }

        $this->assertSame(0, CustomOrderAlternative::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], $this->customerNotifications());
    }

    public function test_completing_records_the_price_paid_per_item_and_returns_the_purchased_items(): void
    {
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $first = $order->items()->first();
        $first->update(['quantity' => 2]);
        $second = CustomOrderItem::factory()->create(['custom_order_id' => $order->id, 'product_name' => 'Rose bouquet', 'quantity' => 1, 'sort_order' => 1]);

        $this->moveTo($order, 'purchased', ['items' => [
            ['id' => $first->id, 'unit_price' => 150],
            ['id' => $second->id, 'unit_price' => '75.5'],
        ]])->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.items.0', [
                'item_id'      => $first->id,
                'product_name' => 'Oud perfume 100ml',
                'quantity'     => 2,
                'unit_price'   => 150,
                'total_price'  => 300,
                'currency'     => $order->currency,
            ])
            ->assertJsonPath('data.items.1.unit_price', 75.5)
            ->assertJsonPath('data.items.1.total_price', 75.5)
            // Every item priced and no final amount sent -> their total
            ->assertJsonPath('data.final_amount.amount', 375.5);

        $this->assertEquals(150, $first->fresh()->unit_price);

        // Shown on the details screen too
        $this->getJson("/api/v1/shopper/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.items.0.unit_price.amount', 150)
            ->assertJsonPath('data.items.0.total_price.amount', 300);
    }

    public function test_a_sent_final_amount_is_ignored_and_unpriced_items_stay_null(): void
    {
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $first = $order->items()->first();
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id]);

        $this->moveTo($order, 'completed', ['final_amount' => 99, 'items' => [['id' => $first->id, 'unit_price' => 20]]])->assertOk()
            ->assertJsonPath('data.final_amount', null)    // not every item priced, and 99 is ignored
            ->assertJsonPath('data.items.1.unit_price', null)
            ->assertJsonPath('data.items.1.total_price', null);
        $this->assertNull($order->fresh()->final_amount);
    }

    public function test_item_prices_are_validated_against_the_order(): void
    {
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $other = $this->order(CustomOrder::STATUS_IN_PROGRESS)->items()->first();
        $mine  = $order->items()->first();

        $this->moveTo($order, 'completed', ['items' => [['id' => $other->id, 'unit_price' => 10]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.id', 'data.errors');
        $this->moveTo($order, 'completed', ['items' => [['id' => $mine->id, 'unit_price' => -1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price', 'data.errors');
        $this->moveTo($order, 'completed', ['items' => [['id' => $mine->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price', 'data.errors');

        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertNull($mine->fresh()->unit_price);

        // Earlier steps ignore item prices and do not list items
        $pending = $this->order();
        $this->moveTo($pending, 'accepted', ['items' => [['id' => $pending->items()->first()->id, 'unit_price' => 10]]])->assertOk()
            ->assertJsonMissingPath('data.items');
        $this->assertNull($pending->items()->first()->unit_price);
    }

    /**
     * The invoice form (step 2, "تأكيد وإرسال") as multipart fields.
     */
    protected function invoiceForm(CustomOrder $order, array $overrides = []): array
    {
        return array_merge([
            'invoice_image'    => UploadedFile::fake()->image('invoice.png'),
            'pickup_address_id' => UserAddress::create([
                'user_id' => $this->shopper->id, 'location_name' => 'Panorama Mall', 'city' => 'Riyadh', 'district' => 'Al Mursalat',
                'street' => 'Takhassusi St', 'building_number' => '3120',
            ])->id,
            'shopper_fees'     => 30,
            'items'            => [['id' => $order->items()->first()->id, 'unit_price' => 60]],
        ], $overrides);
    }

    protected function postStatus(CustomOrder $order, array $data)
    {
        Sanctum::actingAs($this->shopper);

        return $this->post("/api/v1/shopper/orders/{$order->id}/status", $data, ['Accept' => 'application/json']);
    }

    public function test_two_step_purchase_status_first_then_the_invoice_on_the_status_endpoint(): void
    {
        Storage::fake('public');
        config(['custom_orders.delivery.fee' => 15]);
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $order->items()->first()->update(['quantity' => 2]);

        // Step 1 - "تم الشراء": the status alone
        $this->moveTo($order, 'purchased')->assertOk()
            ->assertJsonPath('message', __('custom_orders.status_updated'))
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.items.0.unit_price', null)
            ->assertJsonPath('data.invoice', null)
            ->assertJsonPath('data.pricing', null);

        // Step 2 - "تأكيد وإرسال": the invoice form, multipart, same endpoint
        $data = $this->postStatus($order, ['status' => 'purchased'] + $this->invoiceForm($order))->assertOk()
            ->assertJsonPath('message', __('custom_orders.invoice_submitted'))
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.pickup.full_address', '3120, Takhassusi St, Al Mursalat, Riyadh')
            ->assertJsonPath('data.delivery.address', $order->delivery_address)   // the customer's, unchanged
            ->assertJsonPath('data.items.0.unit_price', 60)
            ->assertJsonPath('data.items.0.total_price', 120)
            // Computed: 60 x 2 + 30 shopper fees = 150, + 15 delivery = 165
            ->assertJsonPath('data.pricing.subtotal.amount', 120)
            ->assertJsonPath('data.pricing.shopper_fees.amount', 30)
            ->assertJsonPath('data.pricing.final_amount.amount', 150)
            ->assertJsonPath('data.pricing.delivery_fee.amount', 15)
            ->assertJsonPath('data.pricing.total.amount', 165)
            ->assertJsonPath('data.final_amount.amount', 150)
            ->json('data');

        $order->refresh();
        Storage::disk('public')->assertExists($order->invoice_path);
        $this->assertStringContainsString($order->invoice_path, $data['invoice']['image_url']);

        // The customer heard about the purchase once, not again for the invoice
        $this->assertSame(['notifications.custom_order_waiting_for_payment_title'], $this->customerNotifications());
    }

    public function test_purchase_and_invoice_can_be_sent_together(): void
    {
        Storage::fake('public');
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $order->items()->first()->update(['quantity' => 2]);

        $this->postStatus($order, ['status' => 'completed'] + $this->invoiceForm($order))->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.pricing.total.amount', 150);

        $this->assertNotNull($order->fresh()->purchased_at);
        $this->assertSame(['notifications.custom_order_waiting_for_payment_title'], $this->customerNotifications());
    }

    public function test_an_incomplete_invoice_form_is_rejected_and_nothing_changes(): void
    {
        Storage::fake('public');
        $order = $this->order(CustomOrder::STATUS_IN_PROGRESS);

        // Any invoice field makes the whole form required
        $this->postStatus($order, ['status' => 'purchased', 'shopper_fees' => 10])->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_image', 'pickup_address_id', 'items'], 'data.errors');

        $this->postStatus($order, ['status' => 'purchased'] + $this->invoiceForm($order, [
            'invoice_image' => UploadedFile::fake()->image('big.jpg')->size(3000),
        ]))->assertUnprocessable()->assertJsonValidationErrors('invoice_image', 'data.errors');

        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Invoice fields are ignored on other steps
        $pending = $this->order();
        $this->postStatus($pending, ['status' => 'accepted', 'shopper_fees' => 'x'])->assertOk();
        $this->assertNull($pending->fresh()->shopper_fees);
    }

    public function test_the_invoice_cannot_skip_the_workflow(): void
    {
        Storage::fake('public');
        $order = $this->order(CustomOrder::STATUS_ACCEPTED);

        $this->postStatus($order, ['status' => 'purchased'] + $this->invoiceForm($order))->assertStatus(409);

        $this->assertSame('accepted', $order->fresh()->status);
        $this->assertNull($order->fresh()->invoice_path);
        $this->assertSame([], Storage::disk('public')->allFiles());   // upload removed again
    }

    protected function suggest(CustomOrder $order)
    {
        Sanctum::actingAs($this->shopper);

        return $this->postJson("/api/v1/shopper/orders/{$order->id}/alternatives", [
            'item_id' => $order->items()->first()->id, 'product_name' => 'Oud perfume 50ml', 'price' => 120, 'reason' => 'Out of stock',
        ]);
    }

    public function test_an_order_waiting_for_an_alternative_continues_from_where_it_paused(): void
    {
        // Paused while shopping: more alternatives, then completing (with the invoice too) is still possible
        $shopping = $this->order(CustomOrder::STATUS_IN_PROGRESS);
        $shopping->forceFill(['started_at' => now()])->save();

        $this->suggest($shopping)->assertCreated()->assertJsonPath('data.order.status', 'waiting_for_alternative');
        $this->suggest($shopping)->assertCreated()->assertJsonPath('data.order.status', 'waiting_for_alternative');
        $this->assertSame(2, $shopping->alternatives()->count());

        Sanctum::actingAs($this->shopper);
        $this->getJson("/api/v1/shopper/orders/{$shopping->id}")->assertOk()
            ->assertJsonPath('data.status_badge.key', 'waiting_for_alternative')
            ->assertJsonPath('data.status_badge.label', __('custom_orders.shopper_badges.waiting_for_alternative'))
            ->assertJsonPath('data.tab', 'active')
            ->assertJsonPath('data.actions.next_status', 'waiting_for_payment')
            ->assertJsonPath('data.actions.can_suggest_alternatives', true)
            ->assertJsonPath('data.actions.can_cancel', true);
        $this->getJson('/api/v1/shopper/orders?status=waiting_for_alternative')->assertOk()
            ->assertJsonPath('data.counts.by_status.waiting_for_alternative', 1)
            ->assertJsonPath('data.items.0.id', $shopping->id);

        $this->moveTo($shopping, 'in_progress')->assertStatus(409);
        $this->moveTo($shopping, 'purchased')->assertOk()->assertJsonPath('data.status', 'waiting_for_payment');

        // Paused before shopping started: the next step is still "start shopping"
        $accepted = $this->order(CustomOrder::STATUS_ACCEPTED);
        $this->suggest($accepted)->assertCreated();

        $this->moveTo($accepted, 'completed')->assertStatus(409);
        $this->moveTo($accepted, 'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');

        // ... and it can be given up like any unfinished order
        $other = $this->order(CustomOrder::STATUS_ACCEPTED);
        $this->suggest($other)->assertCreated();
        $this->moveTo($other, 'reject', ['cancellation_reason' => 'Customer unreachable'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Customer: one alternative notification per suggestion, status notifications only for real steps
        $this->assertSame([
            'notifications.custom_order_alternative_title',
            'notifications.custom_order_alternative_title',
            'notifications.custom_order_waiting_for_payment_title',
            'notifications.custom_order_alternative_title',
            'notifications.custom_order_in_progress_title',
            'notifications.custom_order_alternative_title',
            'notifications.custom_order_declined_title',
        ], $this->customerNotifications());
    }

    public function test_the_customer_sees_the_order_waiting_for_their_answer(): void
    {
        $order = $this->order(CustomOrder::STATUS_ACCEPTED);
        $this->suggest($order)->assertCreated();

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/custom-orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_alternative')
            ->assertJsonPath('data.status_label', __('custom_orders.statuses.waiting_for_alternative'));
        $this->getJson('/api/v1/user/custom-orders?tab=active')->assertOk()
            ->assertJsonFragment(['id' => $order->id]);
    }
}
