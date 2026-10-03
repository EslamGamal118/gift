<?php

namespace Tests\Feature\Reviews;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Review;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /user/reviews, /store/reviews, /shopper/reviews: each account's reviews screen.
 */
class ReviewFeedTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected StoreProfile $profile;

    protected User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 14:00:00');

        $this->customer = User::factory()->customer()->create(['name' => 'سلمان الحربي']);
        $this->store = User::factory()->storeOwner()->create();
        $this->profile = StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'عطوري', 'rating_avg' => 0, 'rating_count' => 0]);
        $this->shopper = User::factory()->shopper()->create(['name' => 'خالد']);
        ShopperProfile::factory()->approved()->create(['user_id' => $this->shopper->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A delivered store order reviewed through ReviewService (feeds store_reviews too).
     */
    protected function reviewStoreOrder(User $customer, int $stars, ?string $comment, string $at): Review
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $customer->id, 'store_id' => $this->store->id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => Order::STATUS_DELIVERED, 'payment_status' => Order::PAYMENT_PAID,
        ]);

        return $this->submit($customer, Review::TYPE_ORDER, $order->id, $stars, $comment, $at);
    }

    protected function reviewCustomOrder(User $customer, int $stars, ?string $comment, string $at): Review
    {
        $order = CustomOrder::factory()->completed()->create(['user_id' => $customer->id, 'shopper_id' => $this->shopper->id]);

        return $this->submit($customer, Review::TYPE_CUSTOM_ORDER, $order->id, $stars, $comment, $at);
    }

    protected function submit(User $customer, string $type, int $orderId, int $stars, ?string $comment, string $at): Review
    {
        Carbon::setTestNow($at);
        $review = app(ReviewService::class)->submit($customer, $type, $orderId, [
            'store_rating' => $stars, 'store_comment' => $comment, 'products_rating' => 4, 'products_comment' => null,
        ]);
        Carbon::setTestNow('2026-10-03 14:00:00');

        return $review;
    }

    public function test_store_sees_the_reviews_it_received_with_summary_and_breakdown(): void
    {
        $other = User::factory()->customer()->create(['name' => 'منى العتيبي']);

        $this->reviewStoreOrder($this->customer, 5, 'خدمة سريعة جداً وتوصيل الهدايا مغلفة بشكل رائع', '2026-10-02 18:00:00');
        $this->reviewStoreOrder($other, 4, 'الورد جميل جداً', '2026-10-01 12:00:00');
        $this->reviewStoreOrder($other, 5, null, '2026-09-20 12:00:00');
        $hidden = $this->reviewStoreOrder($other, 1, 'سيئ', '2026-09-29 12:00:00');
        StoreReview::query()->where('order_id', $hidden->reviewable_id)->first()->update(['is_visible' => false]);

        $data = $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/store/reviews')->assertOk()->json('data');

        $this->assertSame('received', $data['perspective']);
        $this->assertSame(['type' => 'store', 'id' => $this->profile->id, 'name' => 'عطوري'], $data['subject']);

        // The hidden 1-star review is left out of the list and the average
        $this->assertEquals(4.7, $data['summary']['average_rating']);
        $this->assertSame(3, $data['summary']['total_reviews']);
        $this->assertSame('بناءً على 3 تقييمات', $data['summary']['total_reviews_label']);
        $this->assertSame([5, 4, 3, 2, 1], array_column($data['summary']['breakdown'], 'stars'));
        $this->assertSame([2, 1, 0, 0, 0], array_column($data['summary']['breakdown'], 'count'));
        $this->assertSame([67, 33, 0, 0, 0], array_column($data['summary']['breakdown'], 'percentage'));

        $this->assertCount(3, $data['items']);
        $first = $data['items'][0];
        $this->assertSame(5, $first['rating']);
        $this->assertSame('أمس', $first['time_ago']);
        $this->assertSame('received', $first['direction']);
        $this->assertSame('store', $first['subject_type']);
        $this->assertSame(['role' => 'customer', 'id' => $this->customer->id, 'name' => 'سلمان الحربي', 'avatar' => null], $first['counterpart']);
        $this->assertSame('order', $first['order']['type']);
        $this->assertNull($first['products_rating']);
        $this->assertSame('منذ يومين', $data['items'][1]['time_ago']);
        $this->assertSame('20 سبتمبر', $data['items'][2]['time_ago']);
    }

    public function test_filters_narrow_the_list_but_not_the_summary(): void
    {
        $this->reviewStoreOrder($this->customer, 5, 'ممتاز', '2026-10-02 18:00:00');
        $this->reviewStoreOrder($this->customer, 3, null, '2026-10-01 12:00:00');
        $this->reviewStoreOrder($this->customer, 4, 'جيد', '2026-09-30 12:00:00');

        $this->actingAs($this->store, 'sanctum');

        $this->getJson('/api/v1/store/reviews?stars=5')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.total_reviews', 3);
        $this->assertSame([5, 4], array_column($this->getJson('/api/v1/store/reviews?with_comment=1&sort=highest')->json('data.items'), 'rating'));
        $this->assertSame([3, 4, 5], array_column($this->getJson('/api/v1/store/reviews?sort=lowest')->json('data.items'), 'rating'));
        $this->getJson('/api/v1/store/reviews?stars=6')->assertUnprocessable();
    }

    public function test_shopper_sees_the_reviews_of_their_custom_orders(): void
    {
        $this->reviewCustomOrder($this->customer, 5, 'تسوق رائع', '2026-10-02 10:00:00');
        $this->reviewCustomOrder($this->customer, 4, null, '2026-10-01 10:00:00');
        // Another shopper's review never shows
        $otherShopper = User::factory()->shopper()->create();
        $order = CustomOrder::factory()->completed()->create(['user_id' => $this->customer->id, 'shopper_id' => $otherShopper->id]);
        $this->submit($this->customer, Review::TYPE_CUSTOM_ORDER, $order->id, 1, 'x', '2026-10-02 11:00:00');

        $data = $this->actingAs($this->shopper, 'sanctum')->getJson('/api/v1/shopper/reviews')->assertOk()->json('data');

        $this->assertSame('shopper', $data['subject']['type']);
        $this->assertEquals(4.5, $data['summary']['average_rating']);
        $this->assertSame(2, $data['summary']['total_reviews']);
        $this->assertCount(2, $data['items']);
        $this->assertSame('shopper', $data['items'][0]['subject_type']);
        $this->assertSame('custom_order', $data['items'][0]['order']['type']);
        $this->assertSame('customer', $data['items'][0]['counterpart']['role']);
    }

    public function test_customer_sees_the_reviews_they_gave_to_stores_and_shoppers(): void
    {
        $this->reviewStoreOrder($this->customer, 5, 'رائع', '2026-10-02 18:00:00');
        $this->reviewCustomOrder($this->customer, 3, 'مقبول', '2026-10-01 10:00:00');
        $this->reviewStoreOrder(User::factory()->customer()->create(), 1, 'not mine', '2026-10-02 19:00:00');

        $this->actingAs($this->customer, 'sanctum');
        $data = $this->getJson('/api/v1/user/reviews')->assertOk()->json('data');

        $this->assertSame('given', $data['perspective']);
        $this->assertNull($data['subject']);
        $this->assertSame(2, $data['summary']['total_reviews']);
        $this->assertEquals(4, $data['summary']['average_rating']);

        [$store, $shopper] = $data['items'];
        $this->assertSame('given', $store['direction']);
        $this->assertSame(['role' => 'store', 'id' => $this->profile->id, 'name' => 'عطوري', 'avatar' => $store['counterpart']['avatar']], $store['counterpart']);
        $this->assertSame(4, $store['products_rating']);
        $this->assertSame('shopper', $shopper['subject_type']);
        $this->assertSame(['role' => 'shopper', 'id' => $this->shopper->id, 'name' => 'خالد', 'avatar' => null], $shopper['counterpart']);
        $this->assertNull($shopper['products_rating']);

        $this->getJson('/api/v1/user/reviews?subject=shopper')->assertJsonCount(1, 'data.items')->assertJsonPath('data.summary.total_reviews', 1);
    }

    public function test_each_screen_is_limited_to_its_role(): void
    {
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/store/reviews')->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/shopper/reviews')->assertForbidden();
        $this->actingAs($this->store, 'sanctum')->getJson('/api/v1/user/reviews')->assertForbidden();
        $this->actingAs($this->shopper, 'sanctum')->getJson('/api/v1/store/reviews')->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/user/reviews')->assertUnauthorized();
    }
}
