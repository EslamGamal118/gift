<?php

namespace Tests\Feature\Shopper;

use App\Models\Category;
use App\Models\CustomOrder;
use App\Models\ShopperProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /shopper/statistics. "Now" is Tuesday 2026-10-06 14:00 in Riyadh, so
 * this week started on Saturday 2026-10-03.
 */
class ShopperStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/api/v1/shopper/statistics';

    protected User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        config(['checkout.delivery.timezone' => 'Asia/Riyadh']);
        Carbon::setTestNow(Carbon::parse('2026-10-06 14:00', 'Asia/Riyadh')->utc());
        $this->shopper = User::factory()->shopper()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A paid order: its fee paid at a Riyadh time.
     */
    protected function paid(string $riyadhTime, float $fee, string $paymentStatus = 'paid', ?User $shopper = null): CustomOrder
    {
        $order = CustomOrder::factory()->assignedTo($shopper ?? $this->shopper)->create(['status' => CustomOrder::STATUS_COMPLETED]);
        $order->forceFill([
            'payment_status' => $paymentStatus, 'shopper_fees' => $fee,
            'paid_at' => Carbon::parse($riyadhTime, 'Asia/Riyadh')->utc(), 'accepted_at' => now()->subMonth(),
        ])->save();

        return $order;
    }

    public function test_weekly_earnings_run_saturday_to_friday_in_local_time_against_the_same_stretch_of_last_week(): void
    {
        // This week: 10 + 15 + 25
        $this->paid('2026-10-03 10:00', 10);          // Saturday
        $this->paid('2026-10-05 20:00', 15);          // Monday evening
        $this->paid('2026-10-06 09:00', 25);          // today
        // Last week up to the same moment: 40 (Friday night after it is not compared)
        $this->paid('2026-09-27 12:00', 40);          // last Sunday
        $this->paid('2026-10-02 23:30', 99);          // last Friday 23:30 local = still last week (20:30 UTC)
        // Never counted
        $this->paid('2026-10-04 12:00', 70, 'refunded');
        $this->paid('2026-10-04 12:00', 80, shopper: User::factory()->shopper()->create());
        $this->paid('2026-09-15 12:00', 500);

        Sanctum::actingAs($this->shopper);
        $data = $this->getJson(self::URL, ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.weekly_earnings.amount', 50)
            ->assertJsonPath('data.weekly_earnings.currency', 'SAR')
            ->assertJsonPath('data.weekly_earnings.growth_percentage', 25)   // 50 vs 40
            ->assertJsonPath('data.weekly_earnings.growth_label', '+25%')
            ->assertJsonPath('data.weekly_earnings.trend', 'up')
            ->assertJsonPath('data.weekly_earnings.period', ['from' => '2026-10-03', 'to' => '2026-10-09', 'timezone' => 'Asia/Riyadh'])
            ->assertJsonPath('data.earnings_trend.trend_growth', 25)
            ->assertJsonPath('data.earnings_trend.trend_growth_label', '+25%')
            ->json('data.earnings_trend.days');

        $this->assertSame(
            ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            array_column($data, 'day'),
        );
        $this->assertSame([10, 0, 15, 25, 0, 0, 0], array_map(fn ($d) => (int) $d['amount'], $data));
        $this->assertSame('السبت', $data[0]['label']);
        $this->assertSame('2026-10-09', $data[6]['date']);
    }

    public function test_metrics_categories_and_rating(): void
    {
        $profile = ShopperProfile::factory()->approved()->create(['user_id' => $this->shopper->id, 'rating_avg' => 4.9, 'rating_count' => 156]);
        $categories = Category::factory()->count(3)->create();
        $profile->categories()->attach($categories->pluck('id'));

        $this->paid('2026-10-04 12:00', 10);
        $this->paid('2026-10-05 12:00', 10);
        CustomOrder::factory()->assignedTo($this->shopper)->create(['status' => CustomOrder::STATUS_IN_PROGRESS])->forceFill(['accepted_at' => now()])->save();
        CustomOrder::factory()->assignedTo($this->shopper)->create(['status' => CustomOrder::STATUS_CANCELLED])->forceFill(['cancelled_by' => CustomOrder::ACTOR_SHOPPER])->save();

        // Shopping trips of 20 and 24 minutes -> 22
        foreach ([20, 24] as $minutes) {
            CustomOrder::factory()->assignedTo($this->shopper)->create(['status' => CustomOrder::STATUS_WAITING_FOR_PAYMENT])
                ->forceFill(['accepted_at' => now(), 'started_at' => now()->subHours(2), 'purchased_at' => now()->subHours(2)->addMinutes($minutes)])->save();
        }

        Sanctum::actingAs($this->shopper);
        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.metrics.completed_orders', 2)
            ->assertJsonPath('data.metrics.active_orders', 3)                  // in progress + 2 waiting for payment
            ->assertJsonPath('data.metrics.acceptance_rate', 83.3)             // 5 accepted / 6 decided
            ->assertJsonPath('data.metrics.acceptance_rate_label', '83.3%')
            ->assertJsonPath('data.metrics.average_shopping_time_minutes', 22)
            ->assertJsonPath('data.metrics.average_shopping_time_label', '22 minutes')
            ->assertJsonPath('data.categories_performance_basis', 'profile_specialties')
            ->assertJsonPath('data.customer_rating', ['average_rating' => 4.9, 'total_reviews_count' => 156]);

        $shares = $response->json('data.categories_performance');
        $this->assertSame([34, 33, 33], array_column($shares, 'percentage'));
        $this->assertSame($categories->sortBy('id')->pluck('id')->all(), array_column($shares, 'category_id'));
        $this->assertNotEmpty($shares[0]['category_name']);
    }

    public function test_a_new_shopper_gets_zeros_and_empty_sections(): void
    {
        Sanctum::actingAs($this->shopper);
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.weekly_earnings.amount', 0)
            ->assertJsonPath('data.weekly_earnings.growth_percentage', 0)
            ->assertJsonPath('data.weekly_earnings.trend', 'flat')
            ->assertJsonPath('data.metrics.completed_orders', 0)
            ->assertJsonPath('data.metrics.acceptance_rate', null)
            ->assertJsonPath('data.metrics.average_shopping_time_minutes', null)
            ->assertJsonPath('data.categories_performance', [])
            ->assertJsonPath('data.customer_rating', ['average_rating' => 0, 'total_reviews_count' => 0])
            ->assertJsonCount(7, 'data.earnings_trend.days');

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson(self::URL)->assertForbidden();
    }
}
