<?php

namespace Tests\Feature\CustomOrder;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use App\Models\CustomOrderItem;
use App\Models\User;
use App\Services\ShopperOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /custom-orders/{id}/alternatives/response: the customer approves or
 * rejects the shopper's suggestions; the order then resumes and the shopper
 * is told.
 */
class AlternativeResponseTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendPushNotification::class]);
        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed']);
        $this->shopper  = User::factory()->shopper()->create();
    }

    /**
     * An order (accepted, or shopping when $started) with $count items, one suggestion each.
     *
     * @return array{0: CustomOrder, 1: list<CustomOrderAlternative>}
     */
    protected function orderWithSuggestions(int $count = 3, bool $started = false): array
    {
        $order = CustomOrder::factory()->assignedTo($this->shopper)->create([
            'user_id' => $this->customer->id,
            'status'  => $started ? CustomOrder::STATUS_IN_PROGRESS : CustomOrder::STATUS_ACCEPTED,
        ]);
        $order->forceFill(['accepted_at' => now(), 'started_at' => $started ? now() : null])->save();

        $alternatives = [];
        foreach (range(1, $count) as $i) {
            $item = CustomOrderItem::factory()->create(['custom_order_id' => $order->id, 'product_name' => "Item {$i}"]);
            $alternatives[] = app(ShopperOrderService::class)->suggestAlternative($this->shopper, $order, [
                'item_id' => $item->id, 'product_name' => "Alternative {$i}", 'price' => 10 * $i, 'reason' => 'Out of stock',
            ]);
        }

        $this->assertSame(CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE, $order->fresh()->status);

        return [$order, $alternatives];
    }

    protected function respond(CustomOrder $order, array $alternatives, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->customer);

        return $this->postJson("/api/v1/custom-orders/{$order->id}/alternatives/response", ['alternatives' => $alternatives]);
    }

    /**
     * @return list<string>
     */
    protected function shopperNotifications(): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $this->shopper->id)->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_answering_every_suggestion_accepts_the_order_again_and_tells_the_shopper(): void
    {
        [$order, [$a, $b, $c]] = $this->orderWithSuggestions();

        $this->respond($order, [
            ['id' => $a->id, 'is_approved' => true],
            ['id' => $b->id, 'is_approved' => false],
            ['id' => $c->id, 'is_approved' => true],
        ])->assertOk()
            ->assertJsonPath('message', __('custom_orders.alternatives_answered'))
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonPath('data.resumed', true)
            ->assertJsonPath('data.order.status', 'accepted')
            ->assertJsonPath('data.order.items.0.alternatives.0.status', 'accepted')
            ->assertJsonPath('data.order.items.1.alternatives.0.status', 'rejected');

        $this->assertSame(['accepted', 'rejected', 'accepted'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
        $this->assertNotNull($a->fresh()->responded_at);
        $this->assertSame(CustomOrder::STATUS_ACCEPTED, $order->fresh()->status);

        // Shopper: in-app + push, with the counts and the order's new status
        $this->assertSame(['notifications.custom_order_alternatives_answered_title'], $this->shopperNotifications());
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->recipientIds === [$this->shopper->id]);
        $content = AppNotification::query()->where('notifiable_id', $this->shopper->id)->sole()->content;
        $this->assertSame(['2', '1', 'accepted'], [$content->data['approved'], $content->data['rejected'], $content->data['status']]);

        // The shopper carries on: start shopping
        Sanctum::actingAs($this->shopper);
        $this->postJson("/api/v1/shopper/orders/{$order->id}/status", ['status' => 'started'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_suggestions_made_while_shopping_resume_shopping_so_the_invoice_can_follow(): void
    {
        [$order, [$a]] = $this->orderWithSuggestions(1, started: true);

        $this->respond($order, [['id' => $a->id, 'status' => 'approved']])->assertOk()
            ->assertJsonPath('data.order.status', 'in_progress');

        $this->assertNotNull($order->fresh()->started_at);   // not restarted
        Sanctum::actingAs($this->shopper);
        $this->postJson("/api/v1/shopper/orders/{$order->id}/status", ['status' => 'purchased'])->assertOk()->assertJsonPath('data.status', 'waiting_for_payment');
    }

    public function test_a_partial_answer_keeps_the_order_waiting_until_the_last_one(): void
    {
        [$order, [$a, $b]] = $this->orderWithSuggestions(2);

        $this->respond($order, [['id' => $a->id, 'status' => 'Rejected']])->assertOk()
            ->assertJsonPath('data.resumed', false)
            ->assertJsonPath('data.order.status', 'waiting_for_alternative');

        $this->respond($order, [['id' => $b->id, 'status' => 'accept']])->assertOk()
            ->assertJsonPath('data.resumed', true)
            ->assertJsonPath('data.order.status', 'accepted');

        $this->assertSame(['rejected', 'accepted'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertCount(2, $this->shopperNotifications());
    }

    public function test_answers_are_validated(): void
    {
        [$order, [$a, $b]] = $this->orderWithSuggestions(2);
        [, [$foreign]] = $this->orderWithSuggestions(1);

        $this->respond($order, [])->assertUnprocessable()->assertJsonValidationErrors('alternatives', 'data.errors');
        $this->respond($order, [['id' => $a->id]])->assertUnprocessable()->assertJsonValidationErrors('alternatives.0.is_approved', 'data.errors');
        $this->respond($order, [['id' => $a->id, 'status' => 'maybe']])->assertUnprocessable()->assertJsonValidationErrors('alternatives.0.is_approved', 'data.errors');
        $this->respond($order, [['id' => $a->id, 'is_approved' => true], ['id' => $a->id, 'is_approved' => false]])
            ->assertUnprocessable()->assertJsonValidationErrors('alternatives.1.id', 'data.errors');

        // Another order's suggestion, or one already answered
        $errors = $this->respond($order, [['id' => $foreign->id, 'is_approved' => true]])->assertUnprocessable()->json('data.errors');
        $this->assertSame(__('custom_orders.alternative_not_pending'), $errors['alternatives.0.id'][0]);

        $this->respond($order, [['id' => $a->id, 'is_approved' => true]])->assertOk();
        $this->respond($order, [['id' => $a->id, 'is_approved' => false]])->assertUnprocessable();
        $this->assertSame('accepted', $a->fresh()->status);

        // Nothing else changed
        $this->assertSame('pending', $b->fresh()->status);
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_only_the_customer_of_an_order_still_being_shopped_can_answer(): void
    {
        [$order, [$a]] = $this->orderWithSuggestions(1);

        $this->respond($order, [['id' => $a->id, 'is_approved' => true]], User::factory()->customer()->create())->assertNotFound();
        $this->respond($order, [['id' => $a->id, 'is_approved' => true]], $this->shopper)->assertForbidden();

        $order->forceFill(['status' => CustomOrder::STATUS_CANCELLED])->save();
        $this->respond($order, [['id' => $a->id, 'is_approved' => true]])->assertStatus(409)
            ->assertJsonPath('data.current_status', 'cancelled');

        $this->assertSame('pending', $a->fresh()->status);
        $this->assertSame([], $this->shopperNotifications());
    }
}
