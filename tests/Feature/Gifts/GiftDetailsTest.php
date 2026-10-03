<?php

namespace Tests\Feature\Gifts;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\AlRajhiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GiftDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected StoreProfile $store;

    protected Category $special;

    protected Product $gift;

    protected Addon $card;

    protected function setUp(): void
    {
        parent::setUp();

        config(['checkout.tax.rate' => 0.15, 'checkout.tax.prices_include_tax' => false]);

        $this->customer = User::factory()->customer()->create();
        $this->store    = StoreProfile::factory()->approved()->create(['store_name' => 'Rose Shop', 'rating_avg' => 4.66, 'rating_count' => 12]);
        $this->special  = Category::factory()->special()->create();
        $this->gift     = Product::factory()->create([
            'store_id'       => $this->store->user_id,
            'category_id'    => $this->special->id,
            'name'           => 'Spa Voucher',
            'price'          => 200,
            'stock_quantity' => 5,
        ]);

        $this->card = Addon::factory()->create(['store_id' => $this->store->user_id, 'name' => 'Greeting Card', 'price' => 25, 'stock_quantity' => 10]);
        $this->card->categories()->attach($this->special->id);

        // Another store's card and an inactive one are never offered
        Addon::factory()->create()->categories()->attach($this->special->id);
        Addon::factory()->inactive()->create(['store_id' => $this->store->user_id])->categories()->attach($this->special->id);
    }

    protected function promo(array $attributes = []): PromoCode
    {
        return PromoCode::query()->create($attributes + [
            'code' => 'GIFT10', 'type' => PromoCode::TYPE_PERCENTAGE, 'value' => 10, 'is_active' => true, 'used_count' => 0,
        ]);
    }

    protected function url(?int $productId = null, array $query = []): string
    {
        return '/api/v1/gifts/details/'.($productId ?? $this->gift->id).($query ? '?'.http_build_query($query) : '');
    }

    public function test_it_returns_the_store_the_gift_its_cards_and_the_summary(): void
    {
        Sanctum::actingAs($this->customer);

        $data = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame('Rose Shop', $data['store']['name']);
        $this->assertSame(4.7, $data['store']['rating']['average']);
        $this->assertSame(12, $data['store']['rating']['count']);
        $this->assertSame('Spa Voucher', $data['gift']['name']);
        $this->assertEquals(200, $data['gift']['price']['amount']);
        $this->assertSame($this->special->id, $data['gift']['category']['id']);
        $this->assertSame(5, $data['gift']['max_quantity']);

        $this->assertSame([$this->card->id], array_column($data['cards'], 'id'));
        $this->assertFalse($data['cards'][0]['is_selected']);

        $this->assertEquals(['subtotal' => 200, 'delivery_fee' => 0, 'discount' => 0, 'tax' => 30, 'total' => 230], array_intersect_key($data['summary'], array_flip(['subtotal', 'delivery_fee', 'discount', 'tax', 'total'])));
        $this->assertTrue($data['can_checkout']);
    }

    public function test_selection_with_a_card_quantity_and_promo_is_priced(): void
    {
        $this->promo();
        Sanctum::actingAs($this->customer);

        $data = $this->getJson($this->url(query: ['quantity' => 2, 'addon_ids' => [$this->card->id], 'promo_code' => 'gift10']))
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['cards'][0]['is_selected']);
        $this->assertSame(['quantity' => 2, 'addon_ids' => [$this->card->id], 'promo_code' => 'GIFT10'], $data['selection']);
        $this->assertSame('GIFT10', $data['promo']['code']);
        $this->assertNull($data['promo_error']);

        // 2 x 200 + 2 x 25 = 450, -10% = 405, +15% tax = 465.75
        $summary = $data['summary'];
        $this->assertEquals([400, 50, 450, 45, 60.75, 465.75], [
            $summary['items_total'], $summary['addons_total'], $summary['subtotal'], $summary['discount'], $summary['tax'], $summary['total'],
        ]);
    }

    public function test_an_unusable_promo_is_reported_without_failing_the_screen(): void
    {
        $this->promo(['min_order_amount' => 1000]);
        Sanctum::actingAs($this->customer);

        $data = $this->getJson($this->url(query: ['promo_code' => 'GIFT10']))->assertOk()->json('data');

        $this->assertNull($data['promo']);
        $this->assertNotEmpty($data['promo_error']);
        $this->assertEquals(0, $data['summary']['discount']);
        $this->assertEquals(230, $data['summary']['total']);
    }

    public function test_only_special_category_products_and_their_cards_are_accepted(): void
    {
        Sanctum::actingAs($this->customer);

        $regular = Product::factory()->create(['store_id' => $this->store->user_id]);
        $otherCard = Addon::factory()->create(['store_id' => $this->store->user_id]); // not linked to the category

        $this->getJson($this->url($regular->id))->assertUnprocessable()->assertJsonValidationErrors('product_id', 'data.errors');
        $this->getJson($this->url(999999))->assertUnprocessable()->assertJsonValidationErrors('product_id', 'data.errors');
        $this->getJson($this->url(query: ['addon_ids' => [$otherCard->id]]))->assertUnprocessable()->assertJsonValidationErrors('addon_ids.0', 'data.errors');
        $this->getJson($this->url(query: ['quantity' => 0]))->assertUnprocessable()->assertJsonValidationErrors('quantity', 'data.errors');
    }

    public function test_it_requires_a_signed_in_customer(): void
    {
        $this->getJson($this->url())->assertUnauthorized();

        Sanctum::actingAs(User::factory()->storeOwner()->create());
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_checkout_charges_exactly_the_summary_total(): void
    {
        $promo = $this->promo();
        $this->mock(AlRajhiService::class, fn ($mock) => $mock->shouldReceive('sendPayment')->andReturn(['success' => true, 'url' => 'https://alrajhi.test/pay']));
        config(['services.alrajhi.base_url' => 'https://alrajhi.test', 'services.alrajhi.transportal_id' => 'T1']);
        Sanctum::actingAs($this->customer);

        $selection = ['quantity' => 2, 'addon_ids' => [$this->card->id], 'promo_code' => 'GIFT10'];
        $summary   = $this->getJson($this->url(query: $selection))->assertOk()->json('data.summary');

        $orderId = $this->postJson('/api/v1/gifts/checkout', $selection + [
            'product_id' => $this->gift->id, 'recipient_name' => 'Noura', 'recipient_phone' => '0555123456', 'gateway' => 'alrajhi',
        ])->assertCreated()->json('data.order_id');

        $order = Order::query()->with('items.addons')->findOrFail($orderId);
        $this->assertEquals($summary['total'], (float) $order->total_amount);
        $this->assertEquals($summary['discount'], (float) $order->discount_amount);
        $this->assertSame('GIFT10', $order->promo_code);
        $this->assertSame([$this->card->id], $order->items->first()->addons->pluck('addon_id')->all());

        $this->assertSame(3, $this->gift->fresh()->stock_quantity);
        $this->assertSame(8, $this->card->fresh()->stock_quantity);
        $this->assertSame(1, $promo->fresh()->used_count);
    }
}
