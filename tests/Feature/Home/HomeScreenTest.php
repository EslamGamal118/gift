<?php

namespace Tests\Feature\Home;

use App\Models\Banner;
use App\Models\Category;
use App\Models\NotificationContent;
use App\Models\Product;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Http\Resources\CompactStoreListingResource;

class HomeScreenTest extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = '/api/v1/home';

    // Riyadh, King Fahd Rd
    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stores.home.cache_ttl' => 0]);
    }

    /**
     * An approved store with one active branch at the given point.
     */
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

    public function test_guest_gets_every_section_with_a_guest_greeting(): void
    {
        Banner::factory()->count(2)->create();
        Category::factory()->count(3)->create();
        $store = $this->storeAt(self::LAT + 0.01, self::LNG);
        $this->productFor($store);

        $response = $this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG);

        $response->assertOk()
            ->assertJsonPath('data.user.is_guest', true)
            ->assertJsonPath('data.user.greeting', __('home.greeting_guest'))
            ->assertJsonPath('data.notifications.unread_count', 0)
            ->assertJsonPath('data.location.source', 'gps')
            ->assertJsonPath('data.location.is_approximate', false)
            ->assertJsonCount(2, 'data.banners')
            ->assertJsonCount(Category::query()->active()->regular()->count(), 'data.categories')
            ->assertJsonCount(1, 'data.featured_products')
            ->assertJsonCount(1, 'data.nearby_stores.items')
            ->assertJsonStructure(['data' => [
                'user', 'location', 'cities', 'notifications', 'banners', 'categories',
                'custom_order' => ['available', 'title', 'description', 'button_text'],
                'featured_products', 'online_gifts', 'nearby_stores' => ['radius_km', 'items'],
            ]]);
    }

    public function test_logged_in_user_gets_personal_greeting_and_unread_count(): void
    {
        $user = User::factory()->create(['name' => 'Sara']);
        Sanctum::actingAs($user);

        NotificationContent::create([
            'title_key' => 'notifications.generic.title',
            'body_key' => 'notifications.generic.body',
            'type' => 'system',
        ])->deliverTo([$user]);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.user.is_guest', false)
            ->assertJsonPath('data.user.name', 'Sara')
            ->assertJsonPath('data.user.greeting', __('home.greeting_user', ['name' => 'Sara']))
            ->assertJsonPath('data.notifications.unread_count', 1);
    }

    public function test_greeting_is_localized_via_accept_language(): void
    {
        $this->getJson(self::ENDPOINT, ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.user.greeting', 'أهلاً بك، زائرنا');
    }

    public function test_nearby_stores_carry_distance_and_are_sorted_nearest_first(): void
    {
        $far  = $this->storeAt(self::LAT + 0.09, self::LNG, ['store_name' => 'Far']);   // ~10 km north, ~12.5 road km
        $near = $this->storeAt(self::LAT + 0.02, self::LNG, ['store_name' => 'Near']);  // ~2.2 km north, ~2.8 road km
        $this->storeAt(self::LAT + 1.0, self::LNG, ['store_name' => 'Out of range']);   // ~111 km

        $response = $this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG);

        $items = $response->assertOk()->json('data.nearby_stores.items');

        $this->assertCount(2, $items);
        $this->assertSame($near->id, $items[0]['id']);
        $this->assertSame($far->id, $items[1]['id']);

        $this->assertEqualsWithDelta(2.78, $items[0]['distance_in_km'], 0.02);
        $this->assertEqualsWithDelta(12.51, $items[1]['distance_in_km'], 0.02);

        // Three flat numbers only; the delivery time grows with the distance (preparation + travel)
        foreach (['distance_km', 'distance', 'delivery'] as $nested) {
            $this->assertArrayNotHasKey($nested, $items[0]);
        }
        $this->assertIsInt($items[0]['delivery_time_minutes']);
        $this->assertGreaterThan($items[0]['delivery_time_minutes'], $items[1]['delivery_time_minutes']);
        $this->assertIsNumeric($items[0]['delivery_fee']);   // JSON writes 10.0 as 10
    }

    public function test_nearby_store_numbers_fall_back_to_zero_when_they_cannot_be_worked_out(): void
    {
        // No customer position on the query: no distance (and so nothing to price it from)
        $store = $this->storeAt(self::LAT + 0.02, self::LNG);
        $card  = (new CompactStoreListingResource(StoreProfile::query()->findOrFail($store->id)))->toArray(request());

        $this->assertSame(0.0, $card['distance_in_km']);
        $this->assertIsFloat($card['delivery_fee']);
        $this->assertIsInt($card['delivery_time_minutes']);
        foreach (['distance_km', 'distance', 'delivery'] as $nested) {
            $this->assertArrayNotHasKey($nested, $card);
        }
    }

    public function test_nearby_store_delivery_fee_is_priced_from_the_road_distance(): void
    {
        $near = $this->storeAt(self::LAT + 0.02, self::LNG, ['delivery_fee' => null, 'preparation_time' => 20]); // ~2.8 road km
        $far  = $this->storeAt(self::LAT + 0.09, self::LNG, ['delivery_fee' => null, 'preparation_time' => 20]); // ~12.5 road km
        $flat = $this->storeAt(self::LAT + 0.05, self::LNG, ['delivery_fee' => 7]);

        $items = collect($this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->json('data.nearby_stores.items'))->keyBy('id');

        // Inside the 3 km base distance: base fee only
        $this->assertEquals(10, $items[$near->id]['delivery_fee']);
        // 12.51 km = 3 base + 9.51 extra -> 10 started km x 1.5 = 15, + 10 base
        $this->assertEquals(25, $items[$far->id]['delivery_fee']);
        // A store's flat fee wins over the distance price
        $this->assertEquals(7, $items[$flat->id]['delivery_fee']);

        // 20 prep + ceil(2.78 / 30 km/h * 60) = 6 travel, +15 buffer
        $this->assertSame(26, $items[$near->id]['delivery_time_minutes']);
        $this->assertEqualsWithDelta(2.78, $items[$near->id]['distance_in_km'], 0.02);
    }

    public function test_signed_in_customer_last_gps_position_is_reused_without_coordinates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Saved address far away: the fresher GPS position must win over it
        $jeddah = config('cities.list.jeddah');
        UserAddress::create([
            'user_id' => $user->id, 'location_name' => 'Home', 'city' => 'jeddah', 'district' => 'Al Rawdah',
            'street' => 'Tahlia St', 'building_number' => '3',
            'latitude' => $jeddah['latitude'], 'longitude' => $jeddah['longitude'], 'is_default' => true,
        ]);
        $store = $this->storeAt(self::LAT + 0.02, self::LNG);

        $this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk()
            ->assertJsonPath('data.location.source', 'gps');

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.location.source', 'last_known')
            ->assertJsonPath('data.location.latitude', self::LAT)
            ->assertJsonPath('data.location.is_approximate', false)
            ->assertJsonPath('data.nearby_stores.items.0.id', $store->id);

        // Other users never see it
        Sanctum::actingAs(User::factory()->create());
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.location.source', 'city');
    }

    public function test_guest_coordinates_are_not_remembered(): void
    {
        $this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG)->assertOk();

        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.location.source', 'city');
    }

    public function test_home_query_count_does_not_grow_with_the_number_of_stores_and_products(): void
    {
        Category::factory()->special()->create();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $store = $this->storeAt(self::LAT + 0.01, self::LNG);
        $this->productFor($store, ['is_featured' => true]);
        $few = $count();

        for ($i = 2; $i <= 6; $i++) {
            $store = $this->storeAt(self::LAT + 0.01 * $i, self::LNG);
            StoreBranch::factory()->at(self::LAT - 0.01 * $i, self::LNG)->create(['store_profile_id' => $store->id]);
            $this->productFor($store, ['is_featured' => true]);
        }
        $many = $count();

        $this->assertSame($few, $many);
    }

    public function test_within_km_narrows_the_nearby_radius(): void
    {
        $this->storeAt(self::LAT + 0.02, self::LNG);  // ~2.2 km
        $this->storeAt(self::LAT + 0.09, self::LNG);  // ~10 km

        $this->getJson(self::ENDPOINT.'?latitude='.self::LAT.'&longitude='.self::LNG.'&within_km=5')
            ->assertOk()
            ->assertJsonPath('data.nearby_stores.radius_km', 5)
            ->assertJsonCount(1, 'data.nearby_stores.items');
    }

    public function test_guest_without_location_falls_back_to_default_city_with_a_prompt(): void
    {
        config(['cities.default' => 'riyadh']);
        $this->storeAt(self::LAT + 0.02, self::LNG);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.location.source', 'city')
            ->assertJsonPath('data.location.is_approximate', true)
            ->assertJsonPath('data.location.city.key', 'riyadh')
            ->assertJsonPath('data.location.prompt', __('home.location_prompt'))
            ->assertJsonCount(1, 'data.nearby_stores.items');
    }

    public function test_manual_city_selection_is_used_as_position(): void
    {
        $jeddah = config('cities.list.jeddah');
        $this->storeAt(self::LAT + 0.02, self::LNG, ['store_name' => 'Riyadh store']);
        $inJeddah = $this->storeAt($jeddah['latitude'] + 0.02, $jeddah['longitude'], ['store_name' => 'Jeddah store']);

        $this->getJson(self::ENDPOINT.'?city=jeddah')
            ->assertOk()
            ->assertJsonPath('data.location.city.key', 'jeddah')
            ->assertJsonCount(1, 'data.nearby_stores.items')
            ->assertJsonPath('data.nearby_stores.items.0.id', $inJeddah->id);
    }

    public function test_unknown_city_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT.'?city=atlantis')->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['city']]]);
        $this->getJson(self::ENDPOINT.'?latitude=91&longitude=10')->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['latitude']]]);
        $this->getJson(self::ENDPOINT.'?latitude=24.7')->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['longitude']]]);
    }

    public function test_logged_in_customer_default_address_is_used_when_no_coordinates_are_sent(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        UserAddress::create([
            'user_id' => $user->id,
            'location_name' => 'Home',
            'city' => 'riyadh',
            'district' => 'Al Olaya',
            'street' => 'King Fahd Rd',
            'building_number' => '12',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'is_default' => true,
        ]);

        $this->storeAt(self::LAT + 0.02, self::LNG);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.location.source', 'address')
            ->assertJsonPath('data.location.is_approximate', false)
            ->assertJsonPath('data.location.prompt', null)
            ->assertJsonCount(1, 'data.nearby_stores.items');
    }

    public function test_featured_products_prefer_flagged_items_and_hide_unavailable_ones(): void
    {
        $store    = $this->storeAt(self::LAT, self::LNG);
        $featured = $this->productFor($store, ['is_featured' => true]);
        $this->productFor($store, ['is_featured' => false]);
        $this->productFor($store, ['is_featured' => true, 'stock_quantity' => 0]);
        Product::factory()->featured()->expired()->create(['store_id' => $store->user_id]);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(1, 'data.featured_products')
            ->assertJsonPath('data.featured_products.0.id', $featured->id)
            ->assertJsonPath('data.featured_products.0.is_featured', true)
            ->assertJsonPath('data.featured_products.0.store.id', $store->id);
    }

    public function test_special_category_products_are_returned_as_online_gifts(): void
    {
        $store   = $this->storeAt(self::LAT, self::LNG);
        $regular = Category::factory()->create();
        $special = Category::factory()->special()->create(['name' => ['en' => 'Online Gifts', 'ar' => 'هدايا أونلاين']]);

        $gift = $this->productFor($store, ['category_id' => $special->id, 'stock_quantity' => 3]);
        $this->productFor($store, ['category_id' => $regular->id]);

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('data.online_gifts.category.id', $special->id)
            ->assertJsonCount(1, 'data.online_gifts.items')
            ->assertJsonPath('data.online_gifts.items.0.id', $gift->id)
            ->assertJsonPath('data.online_gifts.items.0.available_slots', 3)
            ->assertJsonPath('data.online_gifts.items.0.slots_label', '3 slots available')
            ->assertJsonPath('data.online_gifts.items.0.is_digital', true);

        // Special categories are not repeated in the main category list
        $ids = collect($response->json('data.categories'))->pluck('id');
        $this->assertTrue($ids->contains($regular->id));
        $this->assertFalse($ids->contains($special->id));
    }

    public function test_online_gifts_section_is_null_without_a_special_category(): void
    {
        Category::factory()->create();

        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.online_gifts', null);
    }

    public function test_cities_endpoint_lists_selectable_cities(): void
    {
        $this->getJson('/api/v1/cities', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.default', config('cities.default'))
            ->assertJsonCount(count(config('cities.list')), 'data.items')
            ->assertJsonPath('data.items.0.key', 'riyadh')
            ->assertJsonPath('data.items.0.name', 'الرياض');
    }
}
