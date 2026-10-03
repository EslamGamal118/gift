<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sorting, multi-category and smart search filters of /categories/{category}/listings.
 */
class CategoryListingFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected StoreProfile $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->store    = StoreProfile::factory()->approved()->create(['category_id' => $this->category->id]);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'store_id'    => $this->store->user_id,
            'category_id' => $this->category->id,
            'description' => null,
        ]);
    }

    /**
     * @return array<int, int>
     */
    protected function ids(array $query, string $type = 'products'): array
    {
        $url = "/api/v1/categories/{$this->category->id}/listings?".http_build_query($query + ['type' => $type]);

        return array_column($this->getJson($url)->assertOk()->json('data.items'), 'id');
    }

    protected function deliveredOrders(StoreProfile $store, int $count): void
    {
        $customer = User::factory()->customer()->create();

        for ($i = 0; $i < $count; $i++) {
            (new Order)->forceFill([
                'order_number' => Order::generateNumber(), 'user_id' => $customer->id, 'store_id' => $store->user_id,
                'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
                'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
                'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 100,
                'status' => Order::STATUS_DELIVERED, 'payment_status' => Order::PAYMENT_PAID,
            ])->save();
        }
    }

    public function test_only_the_four_listing_sorts_are_accepted(): void
    {
        foreach (['nearest', 'newest', 'name', 'rating'] as $sort) {
            $this->getJson("/api/v1/categories/{$this->category->id}/listings?type=stores&sort={$sort}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('sort', 'data.errors');
        }

        foreach (['popular', 'most_popular', 'price_asc', 'price_desc', 'rating_desc', 'top_rated'] as $sort) {
            $this->getJson("/api/v1/categories/{$this->category->id}/listings?type=products&sort={$sort}")->assertOk();
        }
    }

    public function test_products_sort_by_rating_and_aliases_match(): void
    {
        $low  = $this->product(['rating_avg' => 2.5]);
        $high = $this->product(['rating_avg' => 4.8]);
        $mid  = $this->product(['rating_avg' => 3.9]);

        $this->assertSame([$high->id, $mid->id, $low->id], $this->ids(['sort' => 'rating_desc']));
        $this->assertSame($this->ids(['sort' => 'rating_desc']), $this->ids(['sort' => 'top_rated']));
    }

    public function test_stores_sort_by_popularity_and_by_starting_price(): void
    {
        $busy  = StoreProfile::factory()->approved()->create(['category_id' => $this->category->id]);
        $empty = StoreProfile::factory()->approved()->create(['category_id' => $this->category->id]); // sells nothing
        $this->deliveredOrders($busy, 3);
        $this->deliveredOrders($this->store, 1);

        Product::factory()->create(['store_id' => $busy->user_id, 'category_id' => $this->category->id, 'price' => 80]);
        $this->product(['price' => 30]);
        $this->product(['price' => 200]);

        $this->assertSame([$busy->id, $this->store->id, $empty->id], $this->ids(['sort' => 'most_popular'], 'stores'));
        $this->assertSame([$this->store->id, $busy->id, $empty->id], $this->ids(['sort' => 'price_asc'], 'stores'));
        $this->assertSame([$busy->id, $this->store->id, $empty->id], $this->ids(['sort' => 'price_desc'], 'stores'));
    }

    public function test_category_ids_match_any_of_the_selected_categories(): void
    {
        $second = Category::factory()->create();
        $third  = Category::factory()->create();

        $inRoute  = $this->product();
        $inSecond = $this->product(['category_id' => $second->id]);
        $this->product(['category_id' => $third->id]);

        $this->assertSame([$inRoute->id], $this->ids([]));
        $this->assertEqualsCanonicalizing([$inRoute->id, $inSecond->id], $this->ids(['category_ids' => [$this->category->id, $second->id]]));
        $this->assertSame([$inSecond->id], $this->ids(['category_ids' => (string) $second->id]));

        $secondStore = StoreProfile::factory()->approved()->create(['category_id' => $second->id]);
        $this->assertEqualsCanonicalizing([$this->store->id, $secondStore->id], $this->ids(['category_ids' => "{$this->category->id},{$second->id}"], 'stores'));

        $inactive = Category::factory()->create(['is_active' => false]);
        $this->getJson("/api/v1/categories/{$this->category->id}/listings?category_ids[]={$inactive->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.0', 'data.errors');
    }

    public function test_search_ignores_arabic_letter_variants_and_word_order(): void
    {
        $match = $this->product(['name' => 'باقة ورد أحمر مع شوكولاتة']);
        $this->product(['name' => 'باقة ورد أبيض']);

        $this->assertSame([$match->id], $this->ids(['search' => 'شوكولاته احمر']));
        $this->assertSame([$match->id], $this->ids(['search' => 'أحمر   ورد']));
    }

    public function test_search_tolerates_typos(): void
    {
        $chocolate = $this->product(['name' => 'علبة شوكولاتة فاخرة']);
        $roses     = $this->product(['name' => 'Red roses bouquet']);

        $this->assertSame([$chocolate->id], $this->ids(['search' => 'شوكلاته']));
        $this->assertSame([$roses->id], $this->ids(['search' => 'bouqet rosses']));
        $this->assertSame([], $this->ids(['search' => 'زيت']));
    }

    public function test_store_search_tolerates_typos_and_new_names_are_found(): void
    {
        $this->store->update(['store_name' => 'متجر الزهور الذهبية']);

        $this->assertSame([$this->store->id], $this->ids(['search' => 'الذهبيه الزهور'], 'stores'));

        $this->store->update(['store_name' => 'Golden Flowers']);

        $this->assertSame([$this->store->id], $this->ids(['search' => 'goldan'], 'stores'));
    }
}
