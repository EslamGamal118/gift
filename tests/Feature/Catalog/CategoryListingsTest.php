<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryListingsTest extends TestCase
{
    use RefreshDatabase;

    // Riyadh, King Fahd Rd
    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests check the delivery pricing itself (with free delivery off)
        config(['checkout.free_delivery' => false]);

        $this->category = Category::factory()->create();
    }

    protected function storeAt(float $lat, float $lng, array $attributes = []): StoreProfile
    {
        $store = StoreProfile::factory()->approved()->create($attributes + ['category_id' => $this->category->id]);
        StoreBranch::factory()->main()->at($lat, $lng, 'riyadh')->create(['store_profile_id' => $store->id]);

        return $store;
    }

    protected function orders(StoreProfile $store, int $count, string $status = Order::STATUS_DELIVERED): void
    {
        $customer = User::factory()->customer()->create();

        for ($i = 0; $i < $count; $i++) {
            (new Order)->forceFill([
                'order_number' => Order::generateNumber(), 'user_id' => $customer->id, 'store_id' => $store->user_id,
                'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
                'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
                'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 100,
                'status' => $status,
            ])->save();
        }
    }

    protected function url(array $query = [], bool $withCoordinates = true): string
    {
        $coordinates = $withCoordinates ? ['latitude' => self::LAT, 'longitude' => self::LNG] : [];

        return "/api/v1/categories/{$this->category->id}/listings?".http_build_query($query + $coordinates + ['type' => 'stores']);
    }

    public function test_stores_are_sorted_by_road_distance_with_delivery_pricing(): void
    {
        $far  = $this->storeAt(self::LAT + 0.09, self::LNG, ['delivery_fee' => null, 'preparation_time' => 20]); // ~12.5 road km
        $near = $this->storeAt(self::LAT + 0.02, self::LNG, ['delivery_fee' => null, 'preparation_time' => 20, 'logo' => 'stores/logo.png']); // ~2.8 road km
        $remote = $this->storeAt(self::LAT + 1.0, self::LNG); // ~139 road km: beyond the nearby radius, still listed

        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonPath('data.location.source', 'gps')
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.radius_km', null);

        $items = $response->json('data.items');
        $this->assertSame([$near->id, $far->id, $remote->id], array_column($items, 'id'));

        $this->assertEqualsWithDelta(2.78, $items[0]['distance_in_km'], 0.02);
        $this->assertEquals(10, $items[0]['delivery_fee']);          // inside the 3 km base
        $this->assertEquals(25, $items[1]['delivery_fee']);          // 10 + 10 started km x 1.5
        $this->assertSame(26, $items[0]['delivery_time_minutes']);   // 20 prep + 6 travel
        foreach (['distance_km', 'distance', 'delivery'] as $nested) {   // three flat numbers only
            $this->assertArrayNotHasKey($nested, $items[0]);
        }
        $this->assertFalse($items[0]['is_favorite']);
        $this->assertStringStartsWith('http', $items[0]['logo']);
    }

    public function test_within_km_narrows_the_radius(): void
    {
        $this->storeAt(self::LAT + 0.02, self::LNG); // ~2.8 road km
        $this->storeAt(self::LAT + 0.09, self::LNG); // ~12.5 road km

        $this->getJson($this->url(['within_km' => 5]))
            ->assertOk()
            ->assertJsonPath('data.radius_km', 5)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_equal_distances_fall_back_to_rating_then_sales(): void
    {
        $lowRated  = $this->storeAt(self::LAT + 0.02, self::LNG, ['rating_avg' => 3.0]);
        $topSeller = $this->storeAt(self::LAT + 0.02, self::LNG, ['rating_avg' => 4.5]);
        $fewSales  = $this->storeAt(self::LAT + 0.02, self::LNG, ['rating_avg' => 4.5]);
        $nearest   = $this->storeAt(self::LAT + 0.01, self::LNG, ['rating_avg' => 1.0]);

        $this->orders($topSeller, 3);
        $this->orders($fewSales, 1);
        $this->orders($fewSales, 5, Order::STATUS_CANCELLED); // only delivered orders count

        $items = $this->getJson($this->url())->assertOk()->json('data.items');

        $this->assertSame([$nearest->id, $topSeller->id, $fewSales->id, $lowRated->id], array_column($items, 'id'));
    }

    public function test_stores_without_a_located_branch_come_last_and_pages_cover_every_store(): void
    {
        $unlocated = StoreProfile::factory()->approved()->create(['category_id' => $this->category->id, 'rating_avg' => 5]);
        $far       = $this->storeAt(self::LAT + 2.0, self::LNG);
        $near      = $this->storeAt(self::LAT + 0.01, self::LNG);

        $page1 = $this->getJson($this->url(['per_page' => 2]))->assertOk()->assertJsonPath('data.pagination.total', 3);
        $page2 = $this->getJson($this->url(['per_page' => 2, 'page' => 2]))->assertOk();

        $this->assertSame([$near->id, $far->id], array_column($page1->json('data.items'), 'id'));
        $this->assertSame([$unlocated->id], array_column($page2->json('data.items'), 'id'));
        $this->assertSame(0, $page2->json('data.items.0.distance_in_km'));   // no branch location: 0 (JSON writes 0.0 as 0)
    }

    public function test_stores_selling_current_products_in_the_category_are_included(): void
    {
        $other = Category::factory()->create();

        $seller = $this->storeAt(self::LAT + 0.01, self::LNG, ['category_id' => $other->id]);
        Product::factory()->create(['store_id' => $seller->user_id, 'category_id' => $this->category->id]);

        $expiredOnly = $this->storeAt(self::LAT + 0.01, self::LNG, ['category_id' => $other->id]);
        Product::factory()->expired()->create(['store_id' => $expiredOnly->user_id, 'category_id' => $this->category->id]);

        $unrelated = $this->storeAt(self::LAT + 0.01, self::LNG, ['category_id' => $other->id]);

        $ids = array_column($this->getJson($this->url())->assertOk()->json('data.items'), 'id');

        $this->assertContains($seller->id, $ids);
        $this->assertNotContains($expiredOnly->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertSame(1, $this->getJson($this->url())->json('data.counts.stores'));
    }

    public function test_favorite_stores_come_first_then_by_distance(): void
    {
        $near = $this->storeAt(self::LAT + 0.01, self::LNG);
        $far  = $this->storeAt(self::LAT + 0.05, self::LNG);

        $user = User::factory()->customer()->create();
        Favorite::create(['user_id' => $user->id, 'favoritable_type' => 'store', 'favoritable_id' => $far->id]);
        Sanctum::actingAs($user);

        $items = $this->getJson($this->url())->assertOk()->json('data.items');

        $this->assertSame([$far->id, $near->id], array_column($items, 'id'));
        $this->assertSame([true, false], array_column($items, 'is_favorite'));
    }

    public function test_signed_in_customer_last_gps_position_is_reused(): void
    {
        $store = $this->storeAt(self::LAT + 0.02, self::LNG);
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.location.source', 'gps');

        $response = $this->getJson($this->url(withCoordinates: false))
            ->assertOk()
            ->assertJsonPath('data.location.source', 'last_known')
            ->assertJsonPath('data.items.0.id', $store->id);

        $this->assertEqualsWithDelta(2.78, $response->json('data.items.0.distance_in_km'), 0.02);
    }

    public function test_without_any_location_stores_are_listed_by_rating_without_distance(): void
    {
        $low  = $this->storeAt(self::LAT + 0.01, self::LNG, ['rating_avg' => 3.1]);
        $high = $this->storeAt(self::LAT + 2.0, self::LNG, ['rating_avg' => 4.9]);

        $response = $this->getJson($this->url(withCoordinates: false))
            ->assertOk()
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.radius_km', null);

        $items = $response->json('data.items');
        $this->assertSame([$high->id, $low->id], array_column($items, 'id'));
        $this->assertSame(0, $items[0]['distance_in_km']);   // no position: 0 instead of null
        $this->assertIsNumeric($items[0]['delivery_fee']);
        $this->assertIsInt($items[0]['delivery_time_minutes']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_stores(): void
    {
        $user = User::factory()->customer()->create();
        Sanctum::actingAs($user);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url())->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count(); // first request also stores the user's locale

        $this->storeAt(self::LAT + 0.01, self::LNG);
        $few = $count();

        for ($i = 2; $i <= 6; $i++) {
            $store = $this->storeAt(self::LAT + 0.01 * $i, self::LNG);
            StoreBranch::factory()->at(self::LAT - 0.01 * $i, self::LNG)->create(['store_profile_id' => $store->id]);
            Favorite::create(['user_id' => $user->id, 'favoritable_type' => 'store', 'favoritable_id' => $store->id]);
        }

        $this->assertSame($few, $count());
    }
}
