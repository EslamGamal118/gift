<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use App\Models\Favorite;
use App\Services\DeliveryCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class StoreShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests check the delivery pricing itself (with free delivery off)
        config(['checkout.free_delivery' => false]);
    }

    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected function url(int $id, array $query = [], string $suffix = ''): string
    {
        return "/api/v1/stores/{$id}{$suffix}".($query ? '?'.http_build_query($query) : '');
    }

    protected function storeAt(float $lat, float $lng, array $attributes = []): StoreProfile
    {
        $store = StoreProfile::factory()->approved()->create($attributes);
        StoreBranch::factory()->main()->at($lat, $lng, 'riyadh')->create(['store_profile_id' => $store->id]);

        return $store;
    }

    protected function productFor(StoreProfile $store, array $attributes = []): Product
    {
        return Product::factory()->create(['store_id' => $store->user_id] + $attributes);
    }

    public function test_shows_the_lean_header_with_delivery_fee_time_and_distance(): void
    {
        $store = $this->storeAt(self::LAT + 0.02, self::LNG, ['store_name' => 'Rose Garden', 'delivery_fee' => null, 'preparation_time' => 20]);
        $this->productFor($store);

        $data = $this->getJson($this->url($store->id, ['latitude' => self::LAT, 'longitude' => self::LNG, 'tab' => 'best_sellers', 'sort' => 'best_sellers']))
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.name', 'Rose Garden')
            ->assertJsonPath('data.category.id', $store->category_id)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'description', 'logo', 'cover_image', 'is_open', 'is_favorite', 'rating' => ['average', 'count'],
                'delivery_fee', 'delivery_time_minutes', 'distance_in_km',
                'tabs', 'products' => ['tab', 'items', 'pagination'], 'reviews' => ['total', 'items'],
            ]])
            ->json('data');

        // 2.2 km straight x 1.25 = 2.78 road km: inside the 3 km base -> fee 10; time = 20 prep + 6 travel
        $this->assertEqualsWithDelta(2.78, $data['distance_in_km'], 0.02);
        $this->assertEquals(10, $data['delivery_fee']);
        $this->assertSame(26, $data['delivery_time_minutes']);

        $this->assertSame([], array_values(array_intersect(array_keys($data), [
            'distance', 'distance_km', 'location', 'radius_km', 'within_radius',
            'delivery', 'working_hours', 'branches', 'main_branch',
        ])));
        $this->assertSame(['average', 'count'], array_keys($data['rating']));
    }

    public function test_metrics_fall_back_safely_without_a_position(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG, ['preparation_time' => 30, 'delivery_fee' => 12]);

        $data = $this->getJson($this->url($store->id))->assertOk()->json('data');

        $this->assertSame(0, $data['distance_in_km']);
        $this->assertEquals(12, $data['delivery_fee']);           // store's own fee
        $this->assertSame(30, $data['delivery_time_minutes']);    // preparation only
    }

    public function test_metrics_are_zero_when_the_calculation_fails(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $this->mock(DeliveryCalculatorService::class, fn ($mock) => $mock->shouldReceive('quote')->andThrow(new RuntimeException('pricing config missing')));

        $this->getJson($this->url($store->id, ['latitude' => self::LAT, 'longitude' => self::LNG]))
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.delivery_time_minutes', 0);
    }

    public function test_signed_in_customer_last_gps_position_is_reused(): void
    {
        $store = $this->storeAt(self::LAT + 0.02, self::LNG);
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson($this->url($store->id, ['latitude' => self::LAT, 'longitude' => self::LNG]))->assertOk();

        $this->assertEqualsWithDelta(2.78, $this->getJson($this->url($store->id))->assertOk()->json('data.distance_in_km'), 0.02);
    }

    public function test_store_and_products_carry_is_favorite_with_favorites_first(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $older = $this->productFor($store, ['name' => 'Older']);
        $newer = $this->productFor($store, ['name' => 'Newer']);

        $user = User::factory()->customer()->create();
        Favorite::create(['user_id' => $user->id, 'favoritable_type' => 'store', 'favoritable_id' => $store->id]);
        Favorite::create(['user_id' => $user->id, 'favoritable_type' => 'product', 'favoritable_id' => $older->id]);

        // Guests: nothing is a favorite
        $this->getJson($this->url($store->id, ['tab' => 'all']))
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false)
            ->assertJsonPath('data.products.items.0.id', $newer->id);

        Sanctum::actingAs($user);

        $items = $this->getJson($this->url($store->id, ['tab' => 'all']))
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true)
            ->json('data.products.items');
        $this->assertSame([$older->id, $newer->id], array_column($items, 'id'));
        $this->assertSame([true, false], array_column($items, 'is_favorite'));

        // An explicit price / name order is kept exactly
        $byName = $this->getJson($this->url($store->id, ['tab' => 'all', 'sort' => 'name']))->json('data.products.items');
        $this->assertSame([$newer->id, $older->id], array_column($byName, 'id'));
    }

    public function test_query_count_does_not_grow_with_products_and_favorites(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $user = User::factory()->customer()->create();
        Sanctum::actingAs($user);

        $count = function () use ($store): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url($store->id, ['latitude' => self::LAT, 'longitude' => self::LNG, 'tab' => 'all']))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count(); // first request also stores the user's locale
        $this->productFor($store);
        $few = $count();

        for ($i = 0; $i < 5; $i++) {
            $product = $this->productFor($store);
            Favorite::create(['user_id' => $user->id, 'favoritable_type' => 'product', 'favoritable_id' => $product->id]);
        }

        $this->assertSame($few, $count());
    }

    public function test_hidden_stores_are_not_found(): void
    {
        $pending  = StoreProfile::factory()->pending()->create();
        $blocked  = StoreProfile::factory()->approved()->create(['user_id' => User::factory()->storeOwner()->blocked()]);

        $this->getJson($this->url($pending->id))->assertNotFound();
        $this->getJson($this->url($blocked->id))->assertNotFound();
        $this->getJson($this->url(999999))->assertNotFound();
        $this->getJson('/api/v1/stores/abc')->assertNotFound();
    }

    public function test_tabs_list_best_sellers_all_and_the_store_categories_with_counts(): void
    {
        $store    = $this->storeAt(self::LAT, self::LNG);
        $perfumes = Category::factory()->create(['name' => ['en' => 'Perfumes', 'ar' => 'عطور']]);
        $incense  = Category::factory()->create(['name' => ['en' => 'Incense', 'ar' => 'بخور']]);

        $this->productFor($store, ['category_id' => $perfumes->id]);
        $this->productFor($store, ['category_id' => $perfumes->id]);
        $this->productFor($store, ['category_id' => $incense->id]);
        $this->productFor($store, ['category_id' => $incense->id, 'expiry_date' => now()->subDay()]); // hidden
        Product::factory()->create(['category_id' => $incense->id]);                                  // other store

        $tabs = $this->getJson($this->url($store->id), ['Accept-Language' => 'ar'])->assertOk()->json('data.tabs');

        $this->assertSame(['best_sellers', 'all', (string) $perfumes->id, (string) $incense->id], array_column($tabs, 'key'));
        $this->assertSame([3, 3, 2, 1], array_column($tabs, 'count'));
        $this->assertSame('الأكثر مبيعًا', $tabs[0]['name']);
        $this->assertSame('عطور', $tabs[2]['name']);
        $this->assertSame($perfumes->id, $tabs[2]['category_id']);
    }

    public function test_best_sellers_tab_orders_by_units_sold_on_paid_orders(): void
    {
        $store    = $this->storeAt(self::LAT, self::LNG);
        $customer = User::factory()->create();

        $quiet   = $this->productFor($store, ['name' => 'Quiet', 'rating_count' => 500]);
        $popular = $this->productFor($store, ['name' => 'Popular', 'rating_count' => 0]);
        $unpaid  = $this->productFor($store, ['name' => 'Unpaid', 'rating_count' => 0]);

        $this->sell($customer, $store, $popular, 7, Order::STATUS_DELIVERED);
        $this->sell($customer, $store, $unpaid, 50, Order::STATUS_PENDING_PAYMENT);

        $items = $this->getJson($this->url($store->id, ['tab' => 'best_sellers']))->assertOk()->json('data.products.items');

        $this->assertSame([$popular->id, $quiet->id, $unpaid->id], array_column($items, 'id'));
    }

    public function test_products_are_filtered_by_category_tab_and_paginated(): void
    {
        $store    = $this->storeAt(self::LAT, self::LNG);
        $category = Category::factory()->create();
        $other    = Category::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->productFor($store, ['category_id' => $category->id]);
        }
        $this->productFor($store, ['category_id' => $other->id]);

        $this->getJson($this->url($store->id, ['tab' => $category->id, 'per_page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.products.tab', (string) $category->id)
            ->assertJsonCount(2, 'data.products.items')
            ->assertJsonPath('data.products.pagination.total', 5)
            ->assertJsonPath('data.products.pagination.last_page', 3)
            ->assertJsonPath('data.products.items.0.category.id', $category->id);

        // Dedicated products endpoint for the following pages
        $this->getJson($this->url($store->id, ['tab' => $category->id, 'per_page' => 2, 'page' => 3], '/products'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 3)
            ->assertJsonPath('data.pagination.has_more', false)
            ->assertJsonCount(4, 'data.tabs');

        $this->getJson($this->url($store->id, ['tab' => 'weird']))->assertUnprocessable();
    }

    public function test_products_endpoint_supports_search_stock_and_sort(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $cheap = $this->productFor($store, ['name' => 'Oud Cambodian', 'price' => 50]);
        $dear  = $this->productFor($store, ['name' => 'Oud Royal', 'price' => 900]);
        $this->productFor($store, ['name' => 'Musk', 'price' => 10, 'stock_quantity' => 0]);

        $this->getJson($this->url($store->id, ['tab' => 'all', 'search' => 'oud', 'sort' => 'price_desc'], '/products'))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $dear->id)
            ->assertJsonPath('data.items.1.id', $cheap->id);

        $this->getJson($this->url($store->id, ['tab' => 'all', 'in_stock' => 1, 'sort' => 'price_asc'], '/products'))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $cheap->id);
    }

    public function test_recent_reviews_and_rating_summary(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $sara  = User::factory()->create(['name' => 'Sara Al-Ahmad']);

        StoreReview::factory()->count(3)->rating(5)->create(['store_profile_id' => $store->id, 'created_at' => now()->subDays(10)]);
        StoreReview::factory()->rating(2)->create(['store_profile_id' => $store->id, 'created_at' => now()->subDays(5)]);
        StoreReview::factory()->rating(1)->hidden()->create(['store_profile_id' => $store->id]);
        $latest = StoreReview::factory()->rating(4)->create([
            'store_profile_id' => $store->id, 'user_id' => $sara->id, 'comment' => 'Lovely wrapping', 'created_at' => now()->subHour(),
        ]);

        // Denormalised columns were maintained by the model events
        $store->refresh();
        $this->assertSame(5, $store->rating_count);
        $this->assertEquals(4.2, (float) $store->rating_avg);

        $response = $this->getJson($this->url($store->id))->assertOk();

        $response->assertJsonPath('data.rating.average', 4.2)
            ->assertJsonPath('data.rating.count', 5)
            ->assertJsonMissingPath('data.rating.breakdown') // on GET /stores/{id}/reviews
            ->assertJsonPath('data.reviews.total', 5)
            ->assertJsonCount(5, 'data.reviews.items')
            ->assertJsonPath('data.reviews.items.0.id', $latest->id)
            ->assertJsonPath('data.reviews.items.0.rating', 4)
            ->assertJsonPath('data.reviews.items.0.comment', 'Lovely wrapping')
            ->assertJsonPath('data.reviews.items.0.user.name', 'Sara A.')
            ->assertJsonPath('data.reviews.items.0.is_verified_purchase', false)
            ->assertJsonMissing(['rating' => 1]);
    }

    public function test_reviews_endpoint_paginates_and_filters_by_stars(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        StoreReview::factory()->count(4)->rating(5)->create(['store_profile_id' => $store->id]);
        StoreReview::factory()->count(2)->rating(3)->create(['store_profile_id' => $store->id]);

        $this->getJson($this->url($store->id, ['per_page' => 4], '/reviews'))
            ->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.pagination.total', 6)
            ->assertJsonPath('data.rating.count', 6)
            ->assertJsonPath('data.rating.breakdown', [
                ['stars' => 5, 'count' => 4, 'percentage' => 67],
                ['stars' => 4, 'count' => 0, 'percentage' => 0],
                ['stars' => 3, 'count' => 2, 'percentage' => 33],
                ['stars' => 2, 'count' => 0, 'percentage' => 0],
                ['stars' => 1, 'count' => 0, 'percentage' => 0],
            ]);

        $this->getJson($this->url($store->id, ['rating' => 3], '/reviews'))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.filter.rating', 3)
            ->assertJsonPath('data.items.0.rating', 3);

        $this->getJson($this->url($store->id, ['rating' => 6], '/reviews'))->assertUnprocessable();
    }

    /**
     * Minimal paid/unpaid order with one line for `$product`.
     */
    protected function sell(User $customer, StoreProfile $store, Product $product, int $quantity, string $status): void
    {
        $order = Order::create([
            'order_number' => 'T'.fake()->unique()->numerify('########'),
            'user_id' => $customer->id,
            'store_id' => $store->user_id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd', 'shipping_building_number' => '1',
            'shipping_address' => '1, King Fahd, Olaya, Riyadh',
            'delivery_type' => 'instant',
            'subtotal' => $product->price * $quantity,
            'total_amount' => $product->price * $quantity,
            'status' => $status,
            'payment_status' => $status === Order::STATUS_PENDING_PAYMENT ? 'pending' : 'paid',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $product->price,
            'quantity' => $quantity,
            'subtotal' => $product->price * $quantity,
        ]);
    }
}
