<?php

namespace Tests\Feature\Review;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /orders/{id}/review and /custom-orders/{id}/review: one review per
 * finished order, kept in step with the store, product and shopper ratings.
 */
class OrderReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected StoreProfile $storeProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer     = User::factory()->customer()->create(['name' => 'Sara Ahmed']);
        $this->store        = User::factory()->storeOwner()->create();
        $this->storeProfile = StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'rating_avg' => 0, 'rating_count' => 0]);
    }

    /**
     * @param  list<Product>  $products
     */
    protected function storeOrder(string $status = Order::STATUS_DELIVERED, array $products = [], ?User $customer = null): Order
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => ($customer ?? $this->customer)->id, 'store_id' => $this->store->id,
            'shipping_name' => 'Sara Ahmed', 'shipping_phone' => '966500000001', 'shipping_city' => 'Riyadh',
            'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd', 'shipping_building_number' => '12',
            'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh', 'delivery_type' => 'scheduled', 'currency' => 'SAR',
            'subtotal' => 100, 'delivery_fee' => 15, 'tax_amount' => 17.25, 'discount_amount' => 0, 'total_amount' => 132.25,
            'status' => $status, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ]);

        foreach ($products as $product) {
            $order->items()->create([
                'product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100,
            ]);
        }

        return $order;
    }

    protected function review(string $path, array $data = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->customer);

        return $this->postJson("/api/v1/{$path}/review", $data + [
            'store_rating' => 4, 'store_comment' => '  Fast and well packed  ',
            'products_rating' => 5, 'products_comment' => 'Fresh flowers',
        ]);
    }

    public function test_a_delivered_store_order_is_reviewed_once_and_feeds_the_store_and_product_ratings(): void
    {
        $roses = Product::factory()->create(['store_id' => $this->store->id, 'rating_avg' => 0, 'rating_count' => 0]);
        $order = $this->storeOrder(products: [$roses]);

        $this->review("orders/{$order->id}")->assertCreated()
            ->assertJsonPath('message', __('reviews.submitted'))
            ->assertJsonPath('data.order_type', 'order')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.store', ['rating' => 4, 'comment' => 'Fast and well packed'])
            ->assertJsonPath('data.products', ['rating' => 5, 'comment' => 'Fresh flowers']);

        $review = Review::query()->sole();
        $this->assertSame(['order', $order->id, $this->customer->id], [$review->reviewable_type, $review->reviewable_id, $review->user_id]);
        $this->assertTrue($review->reviewable->is($order));
        $this->assertTrue($order->review->is($review));

        // The store's review (store screen + aggregate) and the product's rating
        $storeReview = StoreReview::query()->where('order_id', $order->id)->sole();
        $this->assertSame([4, 'Fast and well packed', $this->storeProfile->id], [$storeReview->rating, $storeReview->comment, $storeReview->store_profile_id]);
        $this->assertSame(['4.00', 1], [$this->storeProfile->fresh()->rating_avg, $this->storeProfile->fresh()->rating_count]);
        $this->assertSame(['5.00', 1], [$roses->fresh()->rating_avg, $roses->fresh()->rating_count]);

        // A second order with the same product averages in
        $second = $this->storeOrder(products: [$roses]);
        $this->review("orders/{$second->id}", ['store_rating' => 2, 'products_rating' => 2])->assertCreated();
        $this->assertSame(['3.00', 2], [$this->storeProfile->fresh()->rating_avg, $this->storeProfile->fresh()->rating_count]);
        $this->assertSame(['3.50', 2], [$roses->fresh()->rating_avg, $roses->fresh()->rating_count]);

        // Once only
        $this->review("orders/{$order->id}", ['store_rating' => 1])->assertStatus(409)->assertJsonPath('message', __('reviews.already_reviewed'));
        $this->assertSame(2, Review::query()->count());
        $this->assertSame(4, $storeReview->fresh()->rating);
    }

    public function test_a_completed_custom_order_rates_the_personal_shopper(): void
    {
        $shopper = User::factory()->shopper()->create();
        $profile = ShopperProfile::factory()->approved()->create(['user_id' => $shopper->id, 'rating_avg' => 0, 'rating_count' => 0]);
        $order = CustomOrder::factory()->assignedTo($shopper)->create(['user_id' => $this->customer->id, 'status' => CustomOrder::STATUS_COMPLETED]);

        $this->review("custom-orders/{$order->id}", ['store_rating' => 5, 'store_comment' => null, 'products_rating' => 3])->assertCreated()
            ->assertJsonPath('data.order_type', 'custom_order')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.store', ['rating' => 5, 'comment' => null]);

        $this->assertSame(['5.00', 1], [$profile->fresh()->rating_avg, $profile->fresh()->rating_count]);
        $this->assertSame(0, StoreReview::query()->count());
        $this->assertSame('custom_order', $order->review->reviewable_type);

        // Same id as a store order: they are told apart by the route, each reviewed on its own
        $storeOrder = $this->storeOrder();
        $this->assertSame('custom_order', Review::query()->sole()->reviewable_type);
        $this->review("orders/{$storeOrder->id}")->assertCreated();

        $this->review("custom-orders/{$order->id}")->assertStatus(409);
    }

    public function test_only_finished_orders_of_the_customer_can_be_reviewed(): void
    {
        $this->review('orders/'.$this->storeOrder(Order::STATUS_PROCESSING)->id)->assertStatus(409)
            ->assertJsonPath('message', __('reviews.not_reviewable'))
            ->assertJsonPath('data.current_status', 'processing');

        $inProgress = CustomOrder::factory()->create(['user_id' => $this->customer->id, 'status' => CustomOrder::STATUS_IN_PROGRESS]);
        $this->review("custom-orders/{$inProgress->id}")->assertStatus(409);

        $someoneElses = $this->storeOrder(customer: User::factory()->customer()->create());
        $this->review("orders/{$someoneElses->id}")->assertNotFound();
        $this->review('orders/999999')->assertNotFound();

        // Not for shoppers / stores
        $this->review('orders/'.$this->storeOrder()->id, [], $this->store)->assertForbidden();

        $this->assertSame(0, Review::query()->count());
    }

    public function test_ratings_and_comments_are_validated(): void
    {
        $order = $this->storeOrder();

        $this->review("orders/{$order->id}", ['store_rating' => null, 'products_rating' => null])->assertUnprocessable()
            ->assertJsonValidationErrors(['store_rating', 'products_rating'], 'data.errors');
        $this->review("orders/{$order->id}", ['store_rating' => 0, 'products_rating' => 6])->assertUnprocessable()
            ->assertJsonValidationErrors(['store_rating', 'products_rating'], 'data.errors');
        $this->review("orders/{$order->id}", ['store_rating' => 4.5])->assertUnprocessable()
            ->assertJsonValidationErrors('store_rating', 'data.errors');
        $this->review("orders/{$order->id}", ['store_comment' => str_repeat('a', 501), 'products_comment' => str_repeat('b', 501)])->assertUnprocessable()
            ->assertJsonValidationErrors(['store_comment', 'products_comment'], 'data.errors');

        $this->assertSame(0, Review::query()->count());
    }
}
