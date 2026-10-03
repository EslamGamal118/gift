<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutGroup;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\AlRajhiEncryptionService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One cart with products of several stores: a single payment for the whole
 * cart, split into one independent order per store once it is paid.
 */
class MultiVendorCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected Product $roses;   // store A

    protected Product $cake;    // store B

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 08:00:00');

        foreach ([
            'ALRAJHI_BASE_URL' => 'https://alrajhi.test', 'ALRAJHI_TRANSPORTAL_ID' => 'T1', 'ALRAJHI_PASSWORD' => 'secret',
            'ALRAJHI_ENCRYPTION_KEY' => str_repeat('k', 32), 'ALRAJHI_IV' => 'iv-1234567890123',
        ] as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }

        config([
            'services.alrajhi.base_url' => 'https://alrajhi.test', 'services.alrajhi.transportal_id' => 'T1',
            'checkout.tax.rate' => 0.15, 'checkout.tax.prices_include_tax' => false,
        ]);

        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed', 'phone' => '966500000001']);
        $this->customer->addresses()->create([
            'location_name' => 'Home', 'city' => 'Riyadh', 'district' => 'Olaya', 'street' => 'King Fahd Rd',
            'building_number' => '12', 'is_default' => true,
        ]);

        $this->roses = $this->productOfNewStore(['price' => 100, 'stock_quantity' => 10]);
        $this->cake = $this->productOfNewStore(['price' => 50, 'stock_quantity' => 10]);

        DeliverySlot::create(['label' => 'Morning', 'period' => 'morning', 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'is_active' => true, 'sort_order' => 1]);

        Http::fake(function (Request $request) {
            $this->sent[$request->url()] = $request->data();

            return str_contains($request->url(), 'alrajhi.test')
                ? Http::response([['status' => '1', 'result' => '777:https://alrajhi.test/hpp']])
                : Http::response([], 404);
        });

        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function productOfNewStore(array $attributes): Product
    {
        $store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $store->id, 'working_hours' => null]);

        return Product::factory()->create(['store_id' => $store->id] + $attributes);
    }

    protected function fillCartFromTwoStores(bool $selectDelivery = true): void
    {
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->roses->id, 'quantity' => 2])->assertCreated();
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->cake->id, 'quantity' => 1])->assertCreated()
            ->assertJsonMissingPath('data.stores')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product.id', $this->roses->id)
            ->assertJsonPath('data.items.0.store.id', $this->roses->store_id)
            ->assertJsonPath('data.items.1.store.id', $this->cake->store_id)
            ->assertJsonPath('data.items_count', 3)
            ->assertJsonPath('data.cart_subtotal', fn ($total) => abs($total - 250) < 0.001)
            ->assertJsonPath('data.delivery', null);

        if (! $selectDelivery) {
            return;
        }

        $this->postJson('/api/v1/checkout/delivery', [
            'delivery_type' => 'scheduled', 'delivery_date' => '2026-10-03', 'delivery_slot_id' => DeliverySlot::first()->id,
        ])->assertOk();
    }

    /**
     * POST /checkout/place-order with AlRajhi: the payment page is opened, nothing is ordered yet.
     *
     * @param  array<string, mixed>  $body
     */
    protected function startCheckout(array $body = []): CheckoutGroup
    {
        $id = $this->postJson('/api/v1/checkout/place-order', $body + ['payment_method' => 'alrajhi'])->assertCreated()
            ->assertJsonPath('data.payment.gateway', 'alrajhi')
            ->assertJsonPath('data.payment.redirect_url', 'https://alrajhi.test/hpp?PaymentID=777')
            ->assertJsonPath('data.checkout.orders_count', null)
            ->json('data.checkout.id');

        return CheckoutGroup::findOrFail($id);
    }

    /**
     * The gateway confirms the last payment opened (AlRajhi callback).
     */
    protected function confirmPayment(): TestResponse
    {
        $plain = json_decode((new AlRajhiEncryptionService)->decrypt($this->sent['https://alrajhi.test/pg/payment/hosted.htm'][0]['trandata']), true);
        $trandata = (new AlRajhiEncryptionService)->encrypt(urlencode(json_encode([['result' => 'CAPTURED', 'trackId' => $plain[0]['trackId']]])));

        return $this->postJson('/api/v1/payments/alrajhi/callback', ['trandata' => $trandata])->assertOk();
    }

    public function test_products_of_several_stores_share_one_cart(): void
    {
        $this->fillCartFromTwoStores();

        $summary = $this->getJson('/api/v1/checkout/summary')->assertOk()
            ->assertJsonCount(2, 'data.stores')
            ->assertJsonPath('data.ready', true);

        $perStore = collect($summary->json('data.stores'))->sum('totals.total');
        $this->assertEqualsWithDelta($perStore, $summary->json('data.totals.total'), 0.001);

        // One flat list of lines, each carrying its store; the per-store entries hold no copy
        $summary->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product.id', $this->roses->id)
            ->assertJsonPath('data.items.0.store.id', $this->roses->store_id)
            ->assertJsonPath('data.items.1.store.id', $this->cake->store_id)
            ->assertJsonPath('data.items.0.line_total', 200)
            ->assertJsonMissingPath('data.stores.0.items')
            ->assertJsonPath('data.stores.0.store.id', $this->roses->store_id)
            ->assertJsonPath('data.stores.0.items_count', 2);
        $this->assertSame(
            ['id', 'product', 'store', 'quantity', 'unit_price', 'addons', 'addons_total', 'line_total'],
            array_keys($summary->json('data.items.0')),
        );
    }

    public function test_delivery_can_be_chosen_in_the_place_order_request(): void
    {
        $this->fillCartFromTwoStores(selectDelivery: false);

        $checkout = $this->startCheckout([
            'delivery_type' => 'scheduled', 'delivery_date' => '2026-10-03', 'delivery_slot_id' => DeliverySlot::first()->id,
        ]);

        $this->assertSame('2026-10-03', $checkout->snapshot['order']['delivery_date']);
    }

    public function test_place_order_validates_delivery_fields_like_the_delivery_endpoint(): void
    {
        $this->fillCartFromTwoStores(selectDelivery: false);

        $this->postJson('/api/v1/checkout/place-order', ['delivery_type' => 'scheduled', 'payment_method' => 'alrajhi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_date', 'delivery_slot_id'], 'data.errors');

        $this->assertSame(0, CheckoutGroup::count());
    }

    public function test_place_order_without_any_delivery_choice_is_rejected(): void
    {
        $this->fillCartFromTwoStores(selectDelivery: false);

        $this->postJson('/api/v1/checkout/place-order', ['payment_method' => 'alrajhi'])->assertStatus(422);

        $this->assertSame(0, CheckoutGroup::count());
    }

    public function test_place_order_needs_a_payment_method(): void
    {
        $this->fillCartFromTwoStores();

        $this->postJson('/api/v1/checkout/place-order')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method'], 'data.errors');

        $this->assertSame(0, CheckoutGroup::count());
    }

    public function test_place_order_opens_the_payment_without_ordering_anything(): void
    {
        $this->fillCartFromTwoStores();

        $checkout = $this->startCheckout();

        // The gateway is asked for the whole cart, under the checkout's key
        $plain = json_decode((new AlRajhiEncryptionService)->decrypt($this->sent['https://alrajhi.test/pg/payment/hosted.htm'][0]['trandata']), true);
        $this->assertMatchesRegularExpression('/^CG-'.$checkout->id.'_\d+$/', $plain[0]['trackId']);
        $this->assertEquals((float) $checkout->total_amount, (float) $plain[0]['amt']);
        $this->assertTrue($checkout->isPayable());

        // No order, stock untouched, cart kept
        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->roses->fresh()->stock_quantity);
        $this->assertSame(10, $this->cake->fresh()->stock_quantity);
        $this->getJson('/api/v1/cart')->assertJsonCount(2, 'data.items')->assertJsonPath('data.items_count', 3);
    }

    public function test_confirmed_payment_creates_one_order_per_store_and_clears_the_cart(): void
    {
        $this->fillCartFromTwoStores();
        $checkout = $this->startCheckout();

        $this->confirmPayment()
            ->assertJsonPath('data.order_type', 'checkout')
            ->assertJsonPath('data.is_paid', true);

        $checkout->refresh()->load('orders.items');
        $this->assertTrue($checkout->isPaid());
        $this->assertSame(1, $checkout->transactions()->where('status', PaymentTransaction::STATUS_CAPTURED)->count());

        $this->assertCount(2, $checkout->orders);
        $this->assertEqualsCanonicalizing([$this->roses->store_id, $this->cake->store_id], $checkout->orders->pluck('store_id')->all());

        // Each order only holds its own store's products, and is paid and visible to its store
        $roses = $checkout->orders->firstWhere('store_id', $this->roses->store_id);
        $this->assertSame([$this->roses->id], $roses->items->pluck('product_id')->all());
        $this->assertEquals(200, (float) $roses->subtotal);
        foreach ($checkout->orders as $order) {
            $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
            $this->assertSame(Order::STATUS_PENDING, $order->status);
            $this->assertSame('Riyadh', $order->shipping_city);
        }

        // The single payment is exactly the sum of the orders
        $this->assertEqualsWithDelta($checkout->orders->sum(fn ($o) => (float) $o->total_amount), (float) $checkout->total_amount, 0.001);

        // Stock taken, cart emptied
        $this->assertSame(8, $this->roses->fresh()->stock_quantity);
        $this->assertSame(9, $this->cake->fresh()->stock_quantity);
        $this->getJson('/api/v1/cart')->assertJsonCount(0, 'data.items')->assertJsonPath('data.delivery', null);

        $this->getJson("/api/v1/checkouts/{$checkout->id}/payment-status")->assertOk()
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonCount(2, 'data.checkout.orders');

        // A repeated confirmation changes nothing
        $this->confirmPayment();
        $this->assertSame(2, Order::count());
        $this->assertSame(8, $this->roses->fresh()->stock_quantity);
    }

    public function test_failed_payment_orders_nothing_and_can_be_retried(): void
    {
        $this->fillCartFromTwoStores();
        $checkout = $this->startCheckout();

        app(PaymentService::class)->failOrderPayment($checkout, [], 'alrajhi');

        $this->assertSame(0, Order::count());
        $this->getJson('/api/v1/cart')->assertJsonCount(2, 'data.items');
        $this->assertTrue($checkout->fresh()->isPayable());

        $this->postJson("/api/v1/checkouts/{$checkout->id}/pay", ['gateway' => 'alrajhi'])->assertOk()
            ->assertJsonPath('data.redirect_url', 'https://alrajhi.test/hpp?PaymentID=777');
    }

    public function test_lines_added_while_paying_stay_in_the_cart(): void
    {
        $this->fillCartFromTwoStores();
        $this->startCheckout();

        $flowers = $this->productOfNewStore(['price' => 30, 'stock_quantity' => 5]);
        $this->postJson('/api/v1/cart/items', ['product_id' => $flowers->id, 'quantity' => 1])->assertCreated();

        $this->confirmPayment();

        $this->assertSame(2, Order::count());
        $this->getJson('/api/v1/cart')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $flowers->id);
    }

    public function test_a_product_sold_out_while_paying_is_still_ordered_and_flagged(): void
    {
        $this->fillCartFromTwoStores();
        $this->startCheckout();

        $this->cake->update(['stock_quantity' => 0]);

        $this->confirmPayment();

        $cakeOrder = Order::query()->where('store_id', $this->cake->store_id)->firstOrFail();
        $this->assertSame(Order::PAYMENT_PAID, $cakeOrder->payment_status);
        $this->assertSame(0, $this->cake->fresh()->stock_quantity);
        $this->assertSame([['name' => $this->cake->name, 'ordered' => 1, 'available' => 0]], $cakeOrder->payment_data['stock_shortfall']);
        $this->assertArrayNotHasKey('stock_shortfall', Order::query()->where('store_id', $this->roses->store_id)->firstOrFail()->payment_data ?? []);
    }

    public function test_platform_promo_is_split_pro_rata_and_counts_as_one_use(): void
    {
        $promo = PromoCode::create(['code' => 'SAVE30', 'type' => PromoCode::TYPE_FIXED, 'value' => 30, 'is_active' => true]);

        $this->fillCartFromTwoStores();
        $this->postJson('/api/v1/checkout/promo', ['code' => 'SAVE30'])->assertOk();

        $checkout = $this->startCheckout();
        $this->assertSame(0, $promo->fresh()->used_count);   // not used until paid

        $this->confirmPayment();
        $checkout->load('orders');

        // 200 + 50 subtotal: 30 off shared 24 / 6
        $this->assertEquals(30, (float) $checkout->discount_amount);
        $this->assertEquals(24, (float) $checkout->orders->firstWhere('store_id', $this->roses->store_id)->discount_amount);
        $this->assertEquals(6, (float) $checkout->orders->firstWhere('store_id', $this->cake->store_id)->discount_amount);
        $this->assertSame(1, $promo->fresh()->used_count);
    }

    public function test_store_specific_promo_only_discounts_that_store(): void
    {
        PromoCode::create(['code' => 'CAKE10', 'type' => PromoCode::TYPE_PERCENTAGE, 'value' => 10, 'store_id' => $this->cake->store_id, 'is_active' => true]);

        $this->fillCartFromTwoStores();
        $this->postJson('/api/v1/checkout/promo', ['code' => 'CAKE10'])->assertOk();

        $this->startCheckout();
        $this->confirmPayment();

        $orders = Order::all();
        $this->assertEquals(0, (float) $orders->firstWhere('store_id', $this->roses->store_id)->discount_amount);
        $this->assertNull($orders->firstWhere('store_id', $this->roses->store_id)->promo_code_id);
        $this->assertEquals(5, (float) $orders->firstWhere('store_id', $this->cake->store_id)->discount_amount);
    }
}
