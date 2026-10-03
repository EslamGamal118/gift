<?php

namespace Tests\Feature\Favorites;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    protected const TOGGLE = '/api/v1/favorites/toggle';

    // Riyadh, King Fahd Rd
    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stores.home.cache_ttl' => 0]);
        $this->customer = User::factory()->customer()->create();
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

    protected function favorite(string $type, int $id): void
    {
        Favorite::create(['user_id' => $this->customer->id, 'favoritable_type' => $type, 'favoritable_id' => $id]);
    }

    protected function nearbyUrl(array $query = []): string
    {
        return '/api/v1/stores/nearby?'.http_build_query($query + ['latitude' => self::LAT, 'longitude' => self::LNG]);
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle
    |--------------------------------------------------------------------------
    */

    public function test_toggle_adds_then_removes_a_product(): void
    {
        $product = $this->productFor($this->storeAt(self::LAT, self::LNG));
        Sanctum::actingAs($this->customer);

        $this->postJson(self::TOGGLE, ['favoritable_type' => 'product', 'favoritable_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('message', __('favorites.added'))
            ->assertJsonPath('data', ['favoritable_type' => 'product', 'favoritable_id' => $product->id, 'is_favorite' => true]);

        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->customer->id, 'favoritable_type' => 'product', 'favoritable_id' => $product->id,
        ]);

        $this->postJson(self::TOGGLE, ['favoritable_type' => 'product', 'favoritable_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('message', __('favorites.removed'))
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_toggle_a_store_with_a_case_insensitive_type(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        Sanctum::actingAs($this->customer);

        $this->postJson(self::TOGGLE, ['favoritable_type' => 'Store', 'favoritable_id' => $store->id])
            ->assertOk()
            ->assertJsonPath('data.favoritable_type', 'store')
            ->assertJsonPath('data.is_favorite', true);

        $this->assertSame($store->id, $this->customer->favorites()->sole()->favoritable->id);
    }

    public function test_toggle_validates_type_and_item(): void
    {
        Sanctum::actingAs($this->customer);
        $pending = StoreProfile::factory()->pending()->create();

        $this->postJson(self::TOGGLE, ['favoritable_type' => 'user', 'favoritable_id' => 1])
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['favoritable_type']]]);
        $this->postJson(self::TOGGLE, ['favoritable_type' => 'store'])
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['favoritable_id']]]);

        $this->postJson(self::TOGGLE, ['favoritable_type' => 'product', 'favoritable_id' => 999999])->assertNotFound();
        // Hidden (not approved) stores cannot be added
        $this->postJson(self::TOGGLE, ['favoritable_type' => 'store', 'favoritable_id' => $pending->id])->assertNotFound();

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_an_item_that_became_hidden_can_still_be_removed(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $this->favorite('store', $store->id);
        $store->update(['status' => 'pending']);

        Sanctum::actingAs($this->customer);

        $this->postJson(self::TOGGLE, ['favoritable_type' => 'store', 'favoritable_id' => $store->id])
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);
    }

    public function test_favorites_require_a_signed_in_customer(): void
    {
        $this->postJson(self::TOGGLE, ['favoritable_type' => 'store', 'favoritable_id' => 1])->assertUnauthorized();
        $this->getJson('/api/v1/favorites')->assertUnauthorized();
    }

    public function test_deleting_an_item_removes_its_favorites(): void
    {
        $product = $this->productFor($this->storeAt(self::LAT, self::LNG));
        $this->favorite('product', $product->id);

        $product->delete();

        $this->assertDatabaseCount('favorites', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | is_favorite on resources
    |--------------------------------------------------------------------------
    */

    public function test_is_favorite_is_true_only_for_the_viewers_favorites(): void
    {
        $liked = $this->storeAt(self::LAT + 0.01, self::LNG);
        $other = $this->storeAt(self::LAT + 0.02, self::LNG);
        $likedProduct = $this->productFor($liked, ['is_featured' => true]);
        $this->productFor($other, ['is_featured' => true]);

        $this->favorite('store', $liked->id);
        $this->favorite('product', $likedProduct->id);
        // Someone else's favorite must not leak
        Favorite::create(['user_id' => User::factory()->customer()->create()->id, 'favoritable_type' => 'store', 'favoritable_id' => $other->id]);

        Sanctum::actingAs($this->customer);
        $home = $this->getJson('/api/v1/home?latitude='.self::LAT.'&longitude='.self::LNG)->assertOk();

        $stores = collect($home->json('data.nearby_stores.items'))->pluck('is_favorite', 'id');
        $this->assertSame([$liked->id => true, $other->id => false], $stores->all());

        $products = collect($home->json('data.featured_products'))->pluck('is_favorite', 'id');
        $this->assertTrue($products[$likedProduct->id]);
        $this->assertCount(1, $products->filter());

        // Details screens
        $this->getJson("/api/v1/stores/{$liked->id}")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->getJson("/api/v1/products/{$likedProduct->id}")->assertOk()->assertJsonPath('data.is_favorite', true);
    }

    public function test_guests_always_get_false(): void
    {
        $store = $this->storeAt(self::LAT + 0.01, self::LNG);
        $this->favorite('store', $store->id);

        $this->getJson($this->nearbyUrl())->assertOk()->assertJsonPath('data.items.0.is_favorite', false);
        $this->getJson("/api/v1/stores/{$store->id}")->assertOk()->assertJsonPath('data.is_favorite', false);
    }

    public function test_is_favorite_adds_no_query_per_item(): void
    {
        Sanctum::actingAs($this->customer);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/home?latitude='.self::LAT.'&longitude='.self::LNG)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // The first request also stores the user's locale: keep it out of the comparison
        $count();

        $store = $this->storeAt(self::LAT + 0.01, self::LNG);
        $this->favorite('product', $this->productFor($store, ['is_featured' => true])->id);
        $few = $count();

        for ($i = 2; $i <= 6; $i++) {
            $store = $this->storeAt(self::LAT + 0.01 * $i, self::LNG);
            $this->favorite('store', $store->id);
            $this->favorite('product', $this->productFor($store, ['is_featured' => true])->id);
        }

        $this->assertSame($few, $count());
    }

    /*
    |--------------------------------------------------------------------------
    | Priority
    |--------------------------------------------------------------------------
    */

    public function test_nearby_stores_list_favorites_first_then_by_distance_within_the_radius(): void
    {
        $near    = $this->storeAt(self::LAT + 0.01, self::LNG);  // ~1.4 road km
        $mid     = $this->storeAt(self::LAT + 0.03, self::LNG);  // ~4.2 road km
        $far     = $this->storeAt(self::LAT + 0.06, self::LNG);  // ~8.3 road km
        $outside = $this->storeAt(self::LAT + 0.5, self::LNG);   // ~70 road km, beyond the radius

        $this->favorite('store', $far->id);
        $this->favorite('store', $mid->id);
        $this->favorite('store', $outside->id);

        Sanctum::actingAs($this->customer);

        $response = $this->getJson($this->nearbyUrl(['within_km' => 20]))->assertOk();

        // Favorites (nearest first), then the rest; the radius still applies
        $this->assertSame([$mid->id, $far->id, $near->id], array_column($response->json('data.items'), 'id'));
        $response->assertJsonPath('data.pagination.total', 3);

        // Pagination follows the same order
        $this->getJson($this->nearbyUrl(['within_km' => 20, 'per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $near->id);
    }

    public function test_guests_keep_the_plain_distance_order(): void
    {
        $near = $this->storeAt(self::LAT + 0.01, self::LNG);
        $far  = $this->storeAt(self::LAT + 0.06, self::LNG);
        $this->favorite('store', $far->id);

        $this->assertSame([$near->id, $far->id], array_column($this->getJson($this->nearbyUrl())->json('data.items'), 'id'));
    }

    public function test_product_listing_boosts_favorites_for_default_sort_but_not_for_price_sort(): void
    {
        $category = Category::factory()->create();
        $store = $this->storeAt(self::LAT, self::LNG);
        $cheap  = $this->productFor($store, ['category_id' => $category->id, 'price' => 10]);
        $pricey = $this->productFor($store, ['category_id' => $category->id, 'price' => 90]);
        $newest = $this->productFor($store, ['category_id' => $category->id, 'price' => 50]);
        $this->favorite('product', $pricey->id);

        Sanctum::actingAs($this->customer);
        $url = "/api/v1/categories/{$category->id}/listings?type=products";

        $this->assertSame([$pricey->id, $newest->id, $cheap->id], array_column($this->getJson($url)->assertOk()->json('data.items'), 'id'));

        $priceAsc = $this->getJson($url.'&sort=price_asc')->assertOk()->json('data.items');
        $this->assertSame([$cheap->id, $newest->id, $pricey->id], array_column($priceAsc, 'id'));
        $this->assertTrue($priceAsc[2]['is_favorite']);
    }

    public function test_home_featured_products_list_favorites_first(): void
    {
        $store = $this->storeAt(self::LAT, self::LNG);
        $older = $this->productFor($store, ['is_featured' => true]);
        $this->productFor($store, ['is_featured' => true]);
        $this->favorite('product', $older->id);

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.featured_products.0.id', $older->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    public function test_lists_the_customers_visible_favorites_most_recent_first(): void
    {
        $first  = $this->storeAt(self::LAT + 0.01, self::LNG);
        $second = $this->storeAt(self::LAT + 0.02, self::LNG);
        $hidden = $this->storeAt(self::LAT + 0.03, self::LNG);
        $product = $this->productFor($first);

        $this->favorite('store', $first->id);
        $this->favorite('store', $second->id);
        $this->favorite('store', $hidden->id);
        $this->favorite('product', $product->id);
        $hidden->update(['status' => 'pending']);

        Sanctum::actingAs($this->customer);

        $stores = $this->getJson('/api/v1/favorites?type=store&latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonPath('data.type', 'store')
            ->assertJsonPath('data.pagination.total', 2)
            ->json('data.items');

        $this->assertSame([$second->id, $first->id], array_column($stores, 'id'));
        $this->assertSame([true, true], array_column($stores, 'is_favorite'));
        $this->assertNotNull($stores[0]['distance_km']);

        $this->getJson('/api/v1/favorites?type=product')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $product->id)
            ->assertJsonPath('data.items.0.is_favorite', true);

        $this->getJson('/api/v1/favorites?type=user')->assertUnprocessable();
    }
}
