<?php

namespace Tests\Feature\Catalog;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\DeliveryCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductShowTest extends TestCase
{
    use RefreshDatabase;

    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected function url(int $id, array $query = []): string
    {
        return "/api/v1/products/{$id}".($query ? '?'.http_build_query($query) : '');
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

    public function test_shows_the_lean_product_with_store_card_and_delivery_metrics(): void
    {
        $store = $this->storeAt(self::LAT + 0.02, self::LNG, [
            'store_name' => 'Rose Garden', 'description' => 'Fresh flowers', 'delivery_fee' => null, 'preparation_time' => 20,
        ]);
        $product = $this->productFor($store, [
            'name' => 'Red Roses Bouquet', 'price' => 149.5, 'preparation_time' => 30,
            'rating_avg' => 4.56, 'rating_count' => 12, 'stock_quantity' => 7,
        ]);

        $data = $this->getJson($this->url($product->id, ['latitude' => self::LAT, 'longitude' => self::LNG]))
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Red Roses Bouquet')
            ->assertJsonPath('data.price', ['amount' => 149.5, 'currency' => 'SAR'])
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonPath('data.stock_quantity', 7)
            ->assertJsonPath('data.rating', ['average' => 4.6, 'count' => 12])
            ->assertJsonPath('data.location.source', 'gps')
            ->assertJsonStructure(['data' => [
                'id', 'name', 'description', 'price', 'image', 'in_stock', 'stock_quantity', 'rating',
                'store', 'delivery_fee', 'delivery_time_minutes', 'distance_in_km',
                'addons' => ['title', 'items'], 'related_products' => ['title', 'items'], 'reviews' => ['total', 'average', 'items'],
                'location',
            ]])
            ->json('data');

        $this->assertSame(
            ['id' => $store->id, 'name' => 'Rose Garden', 'logo' => $data['store']['logo'], 'description' => 'Fresh flowers'],
            $data['store']
        );
        $this->assertSame([], array_values(array_intersect(array_keys($data), [
            'images', 'max_quantity', 'preparation_time', 'category', 'delivery',
        ])));

        // 2.2 km straight x 1.25 = 2.78 road km: inside the 3 km base -> fee 10; time = 30 product prep + 6 travel
        $this->assertEqualsWithDelta(2.78, $data['distance_in_km'], 0.02);
        $this->assertEquals(10, $data['delivery_fee']);
        $this->assertSame(36, $data['delivery_time_minutes']);
    }

    public function test_metrics_fall_back_safely_without_coordinates(): void
    {
        $store   = $this->storeAt(self::LAT, self::LNG, ['delivery_fee' => 12, 'preparation_time' => 20]);
        $product = $this->productFor($store, ['price' => 49, 'preparation_time' => 0]);

        $this->getJson($this->url($product->id))
            ->assertOk()
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.distance_in_km', 0)
            ->assertJsonPath('data.delivery_fee', 12)
            ->assertJsonPath('data.delivery_time_minutes', 20); // falls back to the store's preparation time
    }

    public function test_metrics_are_zero_when_the_calculation_fails(): void
    {
        $product = $this->productFor($this->storeAt(self::LAT + 0.02, self::LNG));
        $this->mock(DeliveryCalculatorService::class, fn ($mock) => $mock->shouldReceive('fee')->andThrow(new RuntimeException('pricing config missing')));

        $data = $this->getJson($this->url($product->id, ['latitude' => self::LAT, 'longitude' => self::LNG]))->assertOk()->json('data');

        $this->assertSame([0, 0], [$data['delivery_fee'], $data['delivery_time_minutes']]);
        $this->assertEqualsWithDelta(2.78, $data['distance_in_km'], 0.02); // computed in SQL, unaffected
    }

    public function test_saved_default_address_is_used_as_location_fallback(): void
    {
        $store    = $this->storeAt(self::LAT + 0.02, self::LNG);
        $product  = $this->productFor($store);
        $customer = User::factory()->customer()->create();

        UserAddress::create([
            'user_id' => $customer->id, 'is_default' => true,
            'location_name' => 'Home', 'city' => 'riyadh', 'district' => 'Olaya', 'street' => 'King Fahd Rd', 'building_number' => '12',
            'latitude' => self::LAT, 'longitude' => self::LNG,
        ]);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson($this->url($product->id))
            ->assertOk()
            ->assertJsonPath('data.location.source', 'address');

        $this->assertEqualsWithDelta(2.78, $response->json('data.distance_in_km'), 0.02);
    }

    public function test_lists_in_stock_addons_linked_to_the_product_category(): void
    {
        $store    = $this->storeAt(self::LAT, self::LNG);
        $flowers  = Category::factory()->create();
        $perfumes = Category::factory()->create();
        $product  = $this->productFor($store, ['category_id' => $flowers->id]);

        $chocolate = Addon::factory()->create(['store_id' => $store->user_id, 'name' => 'Chocolate Box', 'price' => 35]);
        $card      = Addon::factory()->create(['store_id' => $store->user_id, 'name' => 'Greeting Card', 'price' => 10]);
        $soldOut   = Addon::factory()->outOfStock()->create(['store_id' => $store->user_id]);
        $inactive  = Addon::factory()->inactive()->create(['store_id' => $store->user_id]);
        $otherCat  = Addon::factory()->create(['store_id' => $store->user_id]);
        $otherShop = Addon::factory()->create();

        foreach ([$chocolate, $card, $soldOut, $inactive, $otherShop] as $addon) {
            $addon->categories()->attach($flowers->id);
        }
        $otherCat->categories()->attach($perfumes->id);

        $addons = $this->getJson($this->url($product->id))->assertOk()->json('data.addons.items');

        // Cheapest first
        $this->assertSame([$card->id, $chocolate->id], array_column($addons, 'id'));
        $this->assertEquals(10, $addons[0]['price']['amount']);
        $this->assertTrue($addons[0]['in_stock']);
    }

    public function test_related_products_come_from_the_same_store_same_category_first(): void
    {
        $store    = $this->storeAt(self::LAT, self::LNG);
        $flowers  = Category::factory()->create();
        $perfumes = Category::factory()->create();
        $product  = $this->productFor($store, ['category_id' => $flowers->id]);

        $sameCatLow  = $this->productFor($store, ['category_id' => $flowers->id, 'rating_avg' => 3.0]);
        $sameCatHigh = $this->productFor($store, ['category_id' => $flowers->id, 'rating_avg' => 4.9]);
        $otherCat    = $this->productFor($store, ['category_id' => $perfumes->id, 'rating_avg' => 5.0]);
        $this->productFor($store, ['category_id' => $flowers->id, 'stock_quantity' => 0]);           // sold out
        $this->productFor($store, ['category_id' => $flowers->id, 'expiry_date' => now()->subDay()]); // expired
        Product::factory()->create(['category_id' => $flowers->id]);                                 // other store

        $related = $this->getJson($this->url($product->id))->assertOk()->json('data.related_products.items');

        $this->assertSame([$sameCatHigh->id, $sameCatLow->id, $otherCat->id], array_column($related, 'id'));
    }

    public function test_embeds_the_latest_visible_store_reviews(): void
    {
        $store   = $this->storeAt(self::LAT, self::LNG);
        $product = $this->productFor($store);

        StoreReview::factory()->count(4)->create(['store_profile_id' => $store->id, 'created_at' => now()->subDays(5)]);
        $latest = StoreReview::factory()->rating(5)->create(['store_profile_id' => $store->id, 'created_at' => now()]);
        StoreReview::factory()->hidden()->create(['store_profile_id' => $store->id, 'created_at' => now()->addMinute()]);

        $reviews = $this->getJson($this->url($product->id))->assertOk()->json('data.reviews');

        $this->assertSame(5, $reviews['total']);
        $this->assertCount(config('stores.product.recent_reviews'), $reviews['items']);
        $this->assertSame($latest->id, $reviews['items'][0]['id']);
        $this->assertSame(5, $reviews['items'][0]['rating']);
        $this->assertArrayHasKey('name', $reviews['items'][0]['user']);
    }

    public function test_unavailable_products_are_not_found(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);

        $soldOut = $this->productFor($store, ['stock_quantity' => 0]);
        $expired = $this->productFor($store, ['expiry_date' => now()->subDay()]);
        $pending = Product::factory()->create(['store_id' => StoreProfile::factory()->pending()->create()->user_id]);
        $blocked = Product::factory()->create([
            'store_id' => StoreProfile::factory()->approved()->create(['user_id' => User::factory()->storeOwner()->blocked()])->user_id,
        ]);

        $this->getJson($this->url($soldOut->id))->assertNotFound();
        $this->getJson($this->url($expired->id))->assertNotFound();
        $this->getJson($this->url($pending->id))->assertNotFound();
        $this->getJson($this->url($blocked->id))->assertNotFound();
        $this->getJson($this->url(999999))->assertNotFound();
        $this->getJson('/api/v1/products/abc')->assertNotFound();
    }

    public function test_validates_coordinates(): void
    {
        $product = $this->productFor($this->storeAt(self::LAT, self::LNG));

        $this->getJson($this->url($product->id, ['latitude' => 95, 'longitude' => self::LNG]))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['latitude']]]);

        $this->getJson($this->url($product->id, ['latitude' => self::LAT]))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['longitude']]]);
    }
}
