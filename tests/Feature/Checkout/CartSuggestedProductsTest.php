<?php

namespace Tests\Feature\Checkout;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/cart/suggested-products
 */
class CartSuggestedProductsTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/api/v1/cart/suggested-products';

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['checkout.cart.suggestions_limit' => 4]);
        $this->customer = User::factory()->customer()->create();
        Sanctum::actingAs($this->customer);
    }

    protected function store(string $name): User
    {
        $store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $store->id, 'store_name' => $name]);

        return $store;
    }

    /**
     * A product that sold `$sold` units on a paid order.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function product(User $store, string $name, int $sold = 0, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes + [
            'store_id' => $store->id, 'name' => $name, 'stock_quantity' => 10, 'expiry_date' => null,
            'rating_avg' => 0, 'rating_count' => 0,
        ]);

        if ($sold > 0) {
            $order = Order::create([
                'order_number' => Order::generateNumber(), 'user_id' => $this->customer->id, 'store_id' => $store->id,
                'shipping_city' => '-', 'shipping_district' => '-', 'shipping_street' => '-', 'shipping_building_number' => '-', 'shipping_address' => '-',
                'currency' => 'SAR', 'subtotal' => 10, 'total_amount' => 10,
                'status' => Order::STATUS_DELIVERED, 'payment_status' => Order::PAYMENT_PAID,
            ]);
            $order->items()->create(['product_id' => $product->id, 'product_name' => $name, 'unit_price' => 1, 'quantity' => $sold, 'subtotal' => $sold]);
        }

        return $product;
    }

    protected function addToCart(Product $product): void
    {
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    }

    /**
     * @return list<string>
     */
    protected function suggestedNames(): array
    {
        return array_column($this->getJson(self::URL)->assertOk()->json('data.items'), 'name');
    }

    public function test_an_empty_cart_gets_the_best_sellers_of_every_store(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        $this->product($a, 'a-5', 5);
        $this->product($b, 'b-9', 9);
        $this->product($a, 'a-1', 1);
        $this->product($b, 'b-0');
        $this->product($a, 'a-0');

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.source', 'best_sellers');

        // Limit 4: the three that sold, then one of the two that never did (tied)
        $names = $this->suggestedNames();
        $this->assertSame(['b-9', 'a-5', 'a-1'], array_slice($names, 0, 3));
        $this->assertContains($names[3], ['b-0', 'a-0']);
        $this->assertCount(4, $names);
    }

    public function test_a_single_store_cart_gets_that_stores_best_sellers_without_the_cart_items(): void
    {
        $a = $this->store('عطوري');
        $b = $this->store('B');
        $inCart = $this->product($a, 'in-cart', 50);
        $this->product($a, 'a-3', 3);
        $this->product($a, 'a-7', 7);
        $this->product($b, 'other-store', 100);

        $this->addToCart($inCart);

        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('data.source', 'cart_stores');
        $this->assertSame(['a-7', 'a-3'], $this->suggestedNames());

        $item = $response->json('data.items.0');
        $this->assertSame(['id', 'name', 'price', 'currency', 'image_url', 'rating', 'rating_count', 'store'], array_keys($item));
        $this->assertSame(['id' => StoreProfile::query()->where('user_id', $a->id)->value('id'), 'name' => 'عطوري'], $item['store']);
    }

    public function test_a_multi_store_cart_is_split_evenly_and_interleaved(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        $c = $this->store('C');

        $this->addToCart($this->product($a, 'a-cart'));
        $this->addToCart($this->product($b, 'b-cart'));

        foreach ([9, 8, 7, 6] as $sold) {
            $this->product($a, "a-{$sold}", $sold);
        }
        $this->product($b, 'b-2', 2);
        $this->product($b, 'b-1', 1);
        $this->product($b, 'b-0');
        $this->product($c, 'c-100', 100);   // not in the cart

        // limit 4 over 2 stores: 2 each, A first (added first)
        $this->assertSame(['a-9', 'b-2', 'a-8', 'b-1'], $this->suggestedNames());
    }

    public function test_unavailable_products_and_online_gifts_are_never_suggested(): void
    {
        $a = $this->store('A');
        $this->addToCart($this->product($a, 'in-cart'));

        $this->product($a, 'ok', 1);
        $this->product($a, 'out-of-stock', 9, ['stock_quantity' => 0]);
        $this->product($a, 'expired', 9, ['expiry_date' => now()->subDays(2)]);
        $gifts = Category::factory()->create(['is_special' => true, 'is_active' => true]);
        $this->product($a, 'online-gift', 9, ['category_id' => $gifts->id]);

        $blocked = $this->store('Blocked');
        StoreProfile::query()->where('user_id', $blocked->id)->update(['status' => 'rejected']);
        $this->product($blocked, 'hidden-store', 9);

        $this->assertSame(['ok'], $this->suggestedNames());
    }

    public function test_one_query_set_whatever_the_number_of_stores(): void
    {
        $stores = collect(range(1, 4))->map(fn ($i) => $this->store("S{$i}"));
        foreach ($stores as $i => $store) {
            $this->addToCart($this->product($store, "cart-{$i}"));
            $this->product($store, "p-{$i}-a", 2);
            $this->product($store, "p-{$i}-b", 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(4, 'data.items');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // auth + cart + cart lines + ranked products + store profiles: no per-store / per-product queries
        $this->assertLessThanOrEqual(7, $queries);
    }

    public function test_guests_and_other_roles_are_refused(): void
    {
        Sanctum::actingAs($this->store('A'));
        $this->getJson(self::URL)->assertForbidden();
    }
}
