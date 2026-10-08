<?php

namespace Tests\Feature\Checkout;

use App\Models\Cart;
use App\Models\CheckoutGroup;
use App\Models\CustomOrder;
use App\Models\DeliverySlot;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\CustomOrderPricing;
use App\Services\DeliverySchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delivery is on us (config `checkout.free_delivery`, on by default): the
 * customer is never charged a delivery or instant-delivery fee, in the cart,
 * at checkout or on custom orders. Delivery types and slots still work.
 */
class FreeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected UserAddress $address;

    protected StoreProfile $store;

    protected Product $roses;

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
            'stores.delivery.pricing.base_fee' => 10, 'checkout.delivery.instant.fee' => 15, 'custom_orders.delivery.fee' => 20,
        ]);

        $this->customer = User::factory()->customer()->create();
        $this->address = $this->customer->addresses()->create([
            'location_name' => 'Home', 'city' => 'Riyadh', 'district' => 'Olaya', 'street' => 'King Fahd Rd',
            'building_number' => '12', 'is_default' => true, 'latitude' => 24.80, 'longitude' => 46.70,
        ]);

        $owner = User::factory()->storeOwner()->create();
        $this->store = StoreProfile::factory()->approved()->create(['user_id' => $owner->id, 'working_hours' => null, 'delivery_fee' => 25]);
        $this->roses = Product::factory()->create(['store_id' => $owner->id, 'price' => 100, 'stock_quantity' => 10]);

        DeliverySlot::create(['label' => 'Morning', 'period' => 'morning', 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'is_active' => true, 'sort_order' => 1]);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'alrajhi.test')
            ? Http::response([['status' => '1', 'result' => '777:https://alrajhi.test/hpp']])
            : Http::response([], 404));

        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_delivery_type_is_quoted_free_and_paid_pricing_is_kept_behind_the_switch(): void
    {
        $quotes = app(DeliverySchedulingService::class);

        foreach ([Cart::DELIVERY_SCHEDULED, Cart::DELIVERY_INSTANT] as $type) {
            $quote = $quotes->quote($this->store, $this->address, $type);
            $this->assertSame([0.0, 0.0, 0.0], [$quote['delivery_fee'], $quote['express_fee'], $quote['total']], $type);
        }
        // The instant ETA is still given
        $this->assertNotNull($quotes->quote($this->store, $this->address, Cart::DELIVERY_INSTANT)['eta_minutes']);

        config(['checkout.free_delivery' => false]);
        $quote = $quotes->quote($this->store, $this->address, Cart::DELIVERY_INSTANT);
        $this->assertSame([25.0, 15.0, 40.0], [$quote['delivery_fee'], $quote['express_fee'], $quote['total']]);
    }

    public function test_the_cart_and_the_order_charge_nothing_for_delivery(): void
    {
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->roses->id, 'quantity' => 2])->assertCreated();
        $this->postJson('/api/v1/checkout/delivery', [
            'delivery_type' => 'scheduled', 'delivery_date' => '2026-10-03', 'delivery_slot_id' => DeliverySlot::first()->id,
        ])->assertOk()
            ->assertJsonPath('data.quote.delivery_fee', 0)
            ->assertJsonPath('data.quote.express_fee', 0)
            ->assertJsonPath('data.quote.total', 0);
        $this->assertSame(Cart::DELIVERY_SCHEDULED, $this->customer->cart()->first()->delivery_type);

        $id = $this->postJson('/api/v1/checkout/place-order', ['payment_method' => 'alrajhi'])->assertCreated()->json('data.checkout.id');
        $group = CheckoutGroup::findOrFail($id);

        // 200 + VAT on 200 only
        $this->assertEquals([200, 0, 0, 30, 230], [$group->subtotal, $group->delivery_fee, $group->express_fee, $group->tax_amount, $group->total_amount]);
    }

    public function test_store_cards_show_free_delivery(): void
    {
        $this->getJson("/api/v1/stores/{$this->store->id}")->assertOk()->assertJsonPath('data.delivery_fee', 0);
    }

    public function test_custom_orders_are_priced_without_delivery_even_if_a_fee_was_stored(): void
    {
        $order = CustomOrder::factory()->create(['user_id' => $this->customer->id, 'delivery_fee' => 20]);

        $pricing = app(CustomOrderPricing::class)->apply($order, 100, 15, 20);

        $this->assertSame(0.0, $pricing['delivery_fee']);
        $this->assertEquals([0, 115, 17.25, 132.25], [$order->delivery_fee, $order->final_amount, $order->tax_amount, $order->total_amount]);
    }
}
