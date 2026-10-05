<?php

namespace Tests\Feature\Reviews;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /products/{product}/reviews: public, paginated reviews of a product.
 */
class ProductReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected User $store;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 14:00:00');

        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id]);
        $this->product = Product::factory()->create(['store_id' => $this->store->id, 'stock_quantity' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A delivered order of $product, reviewed by $customer at $at.
     */
    protected function review(User $customer, int $stars, ?string $comment, string $at, ?Product $product = null): Review
    {
        $product ??= $this->product;

        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $customer->id, 'store_id' => $this->store->id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => Order::STATUS_DELIVERED, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100,
        ]);

        Carbon::setTestNow($at);
        $review = app(ReviewService::class)->submit($customer, Review::TYPE_ORDER, $order->id, [
            'store_rating' => 5, 'store_comment' => 'store comment', 'products_rating' => $stars, 'products_comment' => $comment,
        ]);
        Carbon::setTestNow('2026-10-03 14:00:00');

        return $review;
    }

    public function test_lists_the_product_reviews_newest_first_with_reviewer_and_pagination(): void
    {
        $sara = User::factory()->customer()->create(['name' => 'Sara Ahmed']);
        $omar = User::factory()->customer()->create(['name' => 'Omar']);

        $this->review($sara, 4, 'Lovely flowers', '2026-10-02 10:00:00');
        $this->review($omar, 5, null, '2026-10-01 10:00:00');
        $this->review($omar, 2, 'Too small', '2026-09-30 10:00:00');
        $this->review($sara, 1, 'Other product', '2026-10-02 12:00:00', Product::factory()->create(['store_id' => $this->store->id]));

        $data = $this->getJson("/api/v1/products/{$this->product->id}/reviews")->assertOk()->json('data');

        $this->assertSame($this->product->id, $data['product_id']);
        $this->assertSame('newest', $data['filters']['sort']);
        $this->assertSame(3, $data['summary']['total_reviews']);
        $this->assertEquals(3.7, $data['summary']['average_rating']);

        $this->assertSame([4, 5, 2], array_column($data['items'], 'rating'));
        $this->assertSame('Lovely flowers', $data['items'][0]['comment']);
        $this->assertSame(['id' => $sara->id, 'name' => 'Sara A.', 'avatar' => null], $data['items'][0]['user']);
        $this->assertTrue($data['items'][0]['is_verified_purchase']);

        $this->assertSame(['current_page' => 1, 'per_page' => 15, 'total' => 3, 'last_page' => 1, 'has_more' => false], $data['pagination']);
    }

    public function test_sorts_by_highest_and_accepts_latest_as_newest(): void
    {
        $customer = User::factory()->customer()->create();

        $this->review($customer, 3, null, '2026-10-02 10:00:00');
        $this->review($customer, 5, null, '2026-09-01 10:00:00');
        $this->review($customer, 1, null, '2026-10-01 10:00:00');

        $url = "/api/v1/products/{$this->product->id}/reviews";

        $this->assertSame([5, 3, 1], array_column($this->getJson("$url?sort=highest")->assertOk()->json('data.items'), 'rating'));
        $this->assertSame([3, 1, 5], array_column($this->getJson("$url?sort=latest")->assertOk()->json('data.items'), 'rating'));
        $this->getJson("$url?sort=random")->assertUnprocessable();
    }

    public function test_paginates(): void
    {
        $customer = User::factory()->customer()->create();

        foreach (range(1, 3) as $day) {
            $this->review($customer, 4, null, "2026-09-0{$day} 10:00:00");
        }

        $data = $this->getJson("/api/v1/products/{$this->product->id}/reviews?per_page=2&page=2")->assertOk()->json('data');

        $this->assertCount(1, $data['items']);
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2, 'has_more' => false], $data['pagination']);
    }

    public function test_unknown_or_unavailable_product_is_not_found(): void
    {
        $this->getJson('/api/v1/products/999999/reviews')->assertNotFound();

        $expired = Product::factory()->expired()->create(['store_id' => $this->store->id]);
        $this->getJson("/api/v1/products/{$expired->id}/reviews")->assertNotFound();

        $this->get('/api/v1/products/abc/reviews')->assertNotFound();
    }
}
