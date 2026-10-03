<?php

namespace Tests\Feature\Shopper;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /shopper/home: the shopper's figures and their newest orders waiting for an answer.
 */
class ShopperHomeTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/api/v1/shopper/home';

    protected User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->shopper = User::factory()->shopper()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function order(string $status, array $attributes = [], ?User $shopper = null, int $items = 1): CustomOrder
    {
        $order = CustomOrder::factory()->assignedTo($shopper ?? $this->shopper)->create(['status' => $status]);
        $order->forceFill($attributes)->save();
        CustomOrderItem::factory()->count($items)->create(['custom_order_id' => $order->id]);

        return $order;
    }

    public function test_figures_cover_the_shoppers_own_orders(): void
    {
        $days = fn (int $n) => now()->subDays($n);

        // Active: 2 taken on this week, 1 the week before, 1 earlier
        $this->order(CustomOrder::STATUS_ACCEPTED, ['accepted_at' => $days(1)]);
        $this->order(CustomOrder::STATUS_IN_PROGRESS, ['accepted_at' => $days(3)]);
        $this->order(CustomOrder::STATUS_WAITING_FOR_PAYMENT, ['accepted_at' => $days(10)]);
        $this->order(CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE, ['accepted_at' => $days(20)]);

        // New (waiting for the shopper)
        $this->order(CustomOrder::STATUS_PENDING);
        $this->order(CustomOrder::STATUS_PENDING);

        // Completed (paid): their fees are the earnings; a refunded one is not
        $this->order(CustomOrder::STATUS_COMPLETED, ['accepted_at' => $days(30), 'payment_status' => 'paid', 'shopper_fees' => 15]);
        $this->order(CustomOrder::STATUS_COMPLETED, ['accepted_at' => $days(31), 'payment_status' => 'paid', 'shopper_fees' => 7]);
        $this->order(CustomOrder::STATUS_CANCELLED, ['accepted_at' => $days(32), 'payment_status' => 'refunded', 'shopper_fees' => 50, 'cancelled_by' => 'system']);

        // Declined by the shopper counts against the rate; a customer cancellation does not
        $this->order(CustomOrder::STATUS_CANCELLED, ['cancelled_by' => CustomOrder::ACTOR_SHOPPER]);
        $this->order(CustomOrder::STATUS_CANCELLED, ['cancelled_by' => CustomOrder::ACTOR_CUSTOMER]);

        // Not theirs / not confirmed yet
        $this->order(CustomOrder::STATUS_PENDING, [], User::factory()->shopper()->create());
        $this->order(CustomOrder::STATUS_COMPLETED, ['payment_status' => 'paid', 'shopper_fees' => 99], User::factory()->shopper()->create());
        $this->order(CustomOrder::STATUS_DRAFT);

        Sanctum::actingAs($this->shopper);
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.stats.active_shopping_tasks.value', 4)
            ->assertJsonPath('data.stats.active_shopping_tasks.growth_percentage', 100)   // 2 this week vs 1
            ->assertJsonPath('data.stats.active_shopping_tasks.growth_label', '+100% vs last week')
            ->assertJsonPath('data.stats.active_shopping_tasks.trend', 'up')
            ->assertJsonPath('data.stats.new_orders_count', 2)
            ->assertJsonPath('data.stats.completed_orders_count', 2)
            ->assertJsonPath('data.stats.acceptance_rate', 87.5)                         // 7 accepted / (7 + 1 declined)
            ->assertJsonPath('data.stats.acceptance_rate_label', '87.5%')
            ->assertJsonPath('data.stats.earnings.amount', 22)
            ->assertJsonPath('data.stats.earnings.currency', 'SAR')
            ->assertJsonCount(2, 'data.new_orders');
    }

    public function test_new_orders_list_the_card_details_newest_first(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'أحمد عبدالله', 'avatar' => 'avatars/ahmed.jpg']);
        $older = $this->order(CustomOrder::STATUS_PENDING, ['submitted_at' => now()->subHour()]);
        $newest = $this->order(CustomOrder::STATUS_PENDING, [
            'user_id' => $customer->id, 'order_number' => 'THD-10254', 'submitted_at' => now()->subMinutes(2),
            'budget_min' => 100, 'budget_max' => 400, 'currency' => 'SAR',
            'delivery_address' => 'شارع العليا العام، حي العليا، الرياض',
        ], items: 3);
        $this->order(CustomOrder::STATUS_ACCEPTED);   // not new

        Sanctum::actingAs($this->shopper);
        $card = $this->getJson(self::URL, ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonCount(2, 'data.new_orders')
            ->assertJsonPath('data.new_orders.1.id', $older->id)
            ->json('data.new_orders.0');

        $this->assertSame($newest->id, $card['id']);
        $this->assertSame('THD-10254', $card['order_number']);
        $this->assertSame('new', $card['status']);
        $this->assertSame('أحمد عبدالله', $card['customer']['name']);
        $this->assertStringEndsWith('avatars/ahmed.jpg', $card['customer']['avatar_url']);
        $this->assertSame(now()->subMinutes(2)->toIso8601String(), $card['created_at']);
        $this->assertSame('منذ دقيقتين', $card['time_ago']);
        $this->assertSame(3, $card['cart_size']);
        $this->assertSame('3 منتجات مطلوبة', $card['cart_size_label']);
        $this->assertSame(['min' => 100, 'max' => 400, 'currency' => 'SAR'], array_intersect_key($card['expected_budget'], array_flip(['min', 'max', 'currency'])));
        $this->assertSame('شارع العليا العام، حي العليا، الرياض', $card['delivery_address']);

        // ?orders_limit
        $this->getJson(self::URL.'?orders_limit=1')->assertOk()->assertJsonCount(1, 'data.new_orders');
        $this->getJson(self::URL.'?orders_limit=0')->assertUnprocessable();
    }

    public function test_growth_is_100_percent_when_last_week_had_no_orders(): void
    {
        foreach (range(1, 6) as $day) {
            $this->order(CustomOrder::STATUS_IN_PROGRESS, ['accepted_at' => now()->subDays($day)]);
        }

        Sanctum::actingAs($this->shopper);
        $this->getJson(self::URL, ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.stats.active_shopping_tasks.value', 6)
            ->assertJsonPath('data.stats.active_shopping_tasks.growth_percentage', 100)
            ->assertJsonPath('data.stats.active_shopping_tasks.growth_label', '+100% مقارنة بالأسبوع الماضي')
            ->assertJsonPath('data.stats.active_shopping_tasks.trend', 'up');
    }

    public function test_a_new_shopper_gets_zeros_and_no_rate_yet(): void
    {
        Sanctum::actingAs($this->shopper);
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.stats.active_shopping_tasks.value', 0)
            ->assertJsonPath('data.stats.active_shopping_tasks.growth_percentage', 0)
            ->assertJsonPath('data.stats.active_shopping_tasks.trend', 'flat')
            ->assertJsonPath('data.stats.new_orders_count', 0)
            ->assertJsonPath('data.stats.acceptance_rate', null)
            ->assertJsonPath('data.stats.earnings.amount', 0)
            ->assertJsonPath('data.new_orders', []);
    }

    public function test_the_query_count_does_not_grow_with_the_orders_and_only_shoppers_get_in(): void
    {
        $this->order(CustomOrder::STATUS_PENDING);
        Sanctum::actingAs($this->shopper);

        $this->getJson(self::URL)->assertOk();   // warm-up (one-off lookups)

        DB::enableQueryLog();
        $this->getJson(self::URL)->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 5) as $i) {
            $this->order(CustomOrder::STATUS_PENDING, [], items: 2);
        }
        DB::flushQueryLog();
        $this->getJson(self::URL)->assertOk();
        $this->assertSame($few, count(DB::getQueryLog()));

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson(self::URL)->assertForbidden();
    }
}
