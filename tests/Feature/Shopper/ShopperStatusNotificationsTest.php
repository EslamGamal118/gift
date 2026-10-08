<?php

namespace Tests\Feature\Shopper;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /shopper/orders/{id}/status: every status change reaches the customer
 * and the assigned shopper.
 */
class ShopperStatusNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    protected function notificationsOf(User $user): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $user->id)->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_the_customer_and_the_shopper_are_told_about_each_step(): void
    {
        Queue::fake([SendPushNotification::class]);
        $customer = User::factory()->customer()->create(['name' => 'Ahmed']);
        $shopper = User::factory()->shopper()->create();
        $order = CustomOrder::factory()->assignedTo($shopper)->create(['user_id' => $customer->id]);

        Sanctum::actingAs($shopper);
        $this->postJson("/api/v1/shopper/orders/{$order->id}/status", ['status' => 'accepted'])->assertOk();
        $this->postJson("/api/v1/shopper/orders/{$order->id}/status", ['status' => 'in_progress'])->assertOk();

        $this->assertSame(['notifications.custom_order_accepted_title', 'notifications.custom_order_in_progress_title'], $this->notificationsOf($customer));
        $this->assertSame(array_fill(0, 2, 'notifications.custom_order_shopper_status_title'), $this->notificationsOf($shopper));

        $content = AppNotification::query()->with('content')->where('notifiable_id', $shopper->id)->latest('id')->first()->content;
        $this->assertSame("The order {$order->order_number} of Ahmed is now: Shopping in progress.", $content->renderBody('en'));
        $this->assertSame(CustomOrder::STATUS_IN_PROGRESS, $content->data['status']);
    }
}
