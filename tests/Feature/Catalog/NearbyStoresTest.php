<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NearbyStoresTest extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = '/api/v1/stores/nearby';

    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected function storeAt(float $lat, float $lng, array $attributes = [], array $branch = []): StoreProfile
    {
        $store = StoreProfile::factory()->approved()->create($attributes);
        StoreBranch::factory()->main()->at($lat, $lng, 'riyadh')->create(['store_profile_id' => $store->id] + $branch);

        return $store;
    }

    protected function url(array $query = []): string
    {
        return self::ENDPOINT.'?'.http_build_query(['latitude' => self::LAT, 'longitude' => self::LNG] + $query);
    }

    public function test_requires_a_position(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.latitude.0', __('home.location_required'));
    }

    public function test_returns_stores_sorted_by_distance_with_precise_values(): void
    {
        $points = [
            'c' => [self::LAT, self::LNG + 0.05],   // ~5.1 km east
            'a' => [self::LAT + 0.005, self::LNG],  // ~0.56 km north
            'b' => [self::LAT + 0.02, self::LNG],   // ~2.2 km north
        ];
        $stores = array_map(fn (array $p) => $this->storeAt($p[0], $p[1]), $points);

        $response = $this->getJson($this->url())->assertOk();

        $items = $response->json('data.items');
        $this->assertSame([$stores['a']->id, $stores['b']->id, $stores['c']->id], array_column($items, 'id'));

        // SQL road distances match the PHP Haversine reference x road factor within rounding (2 decimals)
        foreach (['a', 'b', 'c'] as $i => $key) {
            $expected = Geo::roadDistanceKm(Geo::distanceKm(self::LAT, self::LNG, ...$points[$key]));
            $this->assertEqualsWithDelta($expected, $items[$i]['distance_km'], 0.011);
        }

        $response->assertJsonPath('data.location.source', 'gps')
            ->assertJsonPath('data.pagination.total', 3);
        $this->assertEquals(config('stores.home.nearby_radius_km'), $response->json('data.radius_km'));
    }

    public function test_uses_nearest_branch_of_multi_branch_stores(): void
    {
        $store = $this->storeAt(self::LAT + 0.3, self::LNG); // main branch ~33 km away
        StoreBranch::factory()->at(self::LAT + 0.01, self::LNG)->create(['store_profile_id' => $store->id]); // ~1.1 km straight, ~1.4 road km

        $this->getJson($this->url(['within_km' => 5]))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $store->id);

        $this->assertEqualsWithDelta(1.39, $this->getJson($this->url())->json('data.items.0.distance_km'), 0.1);
    }

    public function test_radius_category_rating_and_search_filters_apply(): void
    {
        $category = Category::factory()->create();

        $match = $this->storeAt(self::LAT + 0.01, self::LNG, ['category_id' => $category->id, 'rating_avg' => 4.8, 'store_name' => 'Rose Garden']);
        $this->storeAt(self::LAT + 0.01, self::LNG, ['category_id' => $category->id, 'rating_avg' => 3.0, 'store_name' => 'Rose Low']);
        $this->storeAt(self::LAT + 0.01, self::LNG, ['rating_avg' => 5.0, 'store_name' => 'Rose Other Category']);
        $this->storeAt(self::LAT + 0.2, self::LNG, ['category_id' => $category->id, 'rating_avg' => 5.0, 'store_name' => 'Rose Far']);

        $this->getJson($this->url(['within_km' => 5, 'category_id' => $category->id, 'min_rating' => 4, 'search' => 'Rose']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $match->id)
            ->assertJsonPath('data.radius_km', 5)
            ->assertJsonPath('data.filters.within_km', '5');
    }

    public function test_ignores_inactive_unlocated_and_unapproved_stores(): void
    {
        $visible = $this->storeAt(self::LAT + 0.01, self::LNG);

        // Approved but branch inactive
        $this->storeAt(self::LAT + 0.01, self::LNG, [], ['is_active' => false]);

        // Approved but branch has no coordinates
        $noCoords = StoreProfile::factory()->approved()->create();
        StoreBranch::factory()->main()->unlocated()->create(['store_profile_id' => $noCoords->id]);

        // Pending review
        $pending = StoreProfile::factory()->pending()->create();
        StoreBranch::factory()->main()->at(self::LAT + 0.01, self::LNG)->create(['store_profile_id' => $pending->id]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $visible->id);
    }

    public function test_city_can_stand_in_for_coordinates(): void
    {
        $jeddah = config('cities.list.jeddah');
        $this->storeAt(self::LAT + 0.01, self::LNG, ['store_name' => 'Riyadh']);
        $inJeddah = $this->storeAt($jeddah['latitude'] + 0.01, $jeddah['longitude'] + 0.01, ['store_name' => 'Jeddah']);

        $this->getJson(self::ENDPOINT.'?city=jeddah')
            ->assertOk()
            ->assertJsonPath('data.location.source', 'city')
            ->assertJsonPath('data.location.is_approximate', true)
            ->assertJsonPath('data.radius_km', $jeddah['radius_km'])
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $inJeddah->id);
    }

    public function test_pagination_works_with_the_distance_radius(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->storeAt(self::LAT + 0.005 * $i, self::LNG);
        }

        $page1 = $this->getJson($this->url(['per_page' => 3]))->assertOk();
        $page1->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_more', true);

        $page2 = $this->getJson($this->url(['per_page' => 3, 'page' => 2]))->assertOk();
        $page2->assertJsonCount(1, 'data.items')->assertJsonPath('data.pagination.has_more', false);

        $this->assertGreaterThan($page1->json('data.items.2.distance_km'), $page2->json('data.items.0.distance_km'));
    }

    public function test_distance_is_rounded_to_two_decimals(): void
    {
        $this->storeAt(self::LAT + 0.0123, self::LNG + 0.0077);

        $km = $this->getJson($this->url())->assertOk()->json('data.items.0.distance_km');

        $this->assertIsFloat($km);
        $this->assertSame(round($km, 2), $km);
    }
}
