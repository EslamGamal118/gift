<?php

namespace Tests\Feature\Order;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\Gift;
use App\Models\Order;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        $this->store    = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'Rose Shop']);
    }

    protected function order(string $status, string $createdAt, ?User $customer = null): Order
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => ($customer ?? $this->customer)->id, 'store_id' => $this->store->id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => $status,
        ]);
        $order->items()->create([
            'product_name' => 'Red roses', 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100,
        ]);
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order;
    }

    protected function customOrder(string $status, string $createdAt, array $attributes = []): CustomOrder
    {
        $order = CustomOrder::factory()->create(['user_id' => $this->customer->id, 'status' => $status] + $attributes);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id]);
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order;
    }

    protected const ORDERS = '/api/v1/user/orders';

    protected const CUSTOM_ORDERS = '/api/v1/user/custom-orders';

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    protected function listed(string $endpoint, array $query = []): array
    {
        $items = $this->actingAs($this->customer, 'sanctum')
            ->getJson($endpoint.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->json('data.items');

        return array_map(fn ($i) => [$i['order_type'], $i['id']], $items);
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    protected function search(string $endpoint, string $keyword, array $query = []): array
    {
        $this->actingAs($this->customer, 'sanctum')
            ->getJson($endpoint.'?'.http_build_query(['search' => $keyword] + $query))
            ->assertOk()
            ->assertJsonPath('data.filter.search', trim($keyword));

        return $this->listed($endpoint, ['search' => $keyword] + $query);
    }

    public function test_store_and_custom_orders_have_separate_lists_without_drafts_or_unpaid_orders(): void
    {
        $standardOld = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $custom      = $this->customOrder(CustomOrder::STATUS_PENDING, '2026-09-02 10:00:00');
        $standardNew = $this->order(Order::STATUS_DELIVERED, '2026-09-03 10:00:00');
        $this->customOrder(CustomOrder::STATUS_DRAFT, '2026-09-04 10:00:00');
        $this->order(Order::STATUS_PENDING_PAYMENT, '2026-09-04 10:00:00');
        $this->order(Order::STATUS_PENDING, '2026-09-04 10:00:00', User::factory()->customer()->create());

        $response = $this->actingAs($this->customer, 'sanctum')->getJson(self::ORDERS)->assertOk();
        $items    = $response->json('data.items');

        $this->assertSame([['standard', $standardNew->id], ['standard', $standardOld->id]], array_map(fn ($i) => [$i['order_type'], $i['id']], $items));
        $this->assertSame(2, $response->json('data.pagination.total'));
        $this->assertSame('history', $items[0]['tab']);
        $this->assertArrayNotHasKey('merchant', $items[0]);
        $this->assertSame(['Riyadh', 'Olaya'], [$items[0]['city'], $items[0]['district']]);
        $this->assertSame(115.0, (float) $items[0]['pricing']['total']['amount']);
        $this->assertSame('Red roses', $items[0]['items'][0]['name']);
        $this->assertSame(1, $items[0]['items_count']);

        $response = $this->getJson(self::CUSTOM_ORDERS)->assertOk();
        $item     = $response->json('data.items.0');

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame(['custom', $custom->id], [$item['order_type'], $item['id']]);
        $this->assertSame('active', $item['tab']);
        $this->assertArrayNotHasKey('merchant', $item);
        $this->assertSame([$custom->delivery_city, $custom->delivery_district], [$item['city'], $item['district']]);
        $this->assertNull($item['pricing']['total']);
        $this->assertNotNull($item['pricing']['budget']);
        $this->assertSame(1, $item['items_count']);
    }

    public function test_custom_order_cards_carry_the_shopper_card_and_items_budget(): void
    {
        $shopper = User::factory()->shopper()->create(['name' => 'Huda']);
        ShopperProfile::factory()->approved()->create(['user_id' => $shopper->id, 'bio' => 'Gift hunter', 'rating_avg' => 4.66, 'rating_count' => 9]);
        $assigned = $this->customOrder(CustomOrder::STATUS_ACCEPTED, '2026-09-02 10:00:00', ['shopper_id' => $shopper->id, 'budget_min' => 150, 'budget_max' => 300]);
        $bidding  = $this->customOrder(CustomOrder::STATUS_PENDING, '2026-09-01 10:00:00', ['shopper_id' => null, 'budget_min' => null, 'budget_max' => 80]);

        [$card, $open] = $this->actingAs($this->customer, 'sanctum')->getJson(self::CUSTOM_ORDERS)->assertOk()->json('data.items');

        $this->assertSame(['name', 'photo', 'bio', 'rating'], array_keys($card['shopper']));
        $this->assertSame(['Huda', 'Gift hunter', ['average' => 4.7, 'count' => 9]], [$card['shopper']['name'], $card['shopper']['bio'], $card['shopper']['rating']]);
        $this->assertSame(['currency', 'min', 'max', 'label'], array_keys($card['budget']));
        $this->assertSame(['amount' => 150, 'currency' => 'SAR', 'formatted' => '150.00 SAR'], $card['budget']['min']);
        $this->assertSame('150.00 - 300.00 SAR', $card['budget']['label']);

        // Same objects as the details screen
        $details = $this->getJson(self::CUSTOM_ORDERS."/{$assigned->id}")->json('data');
        $this->assertSame($details['shopper'], $card['shopper']);
        $this->assertSame($details['budget'], $card['budget']);

        $this->assertSame($bidding->id, $open['id']);
        $this->assertNull($open['shopper']);
        $this->assertNull($open['budget']['min']);
        $this->assertSame('80.00 SAR', $open['budget']['label']);

        // Store order cards are unchanged
        $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $this->assertArrayNotHasKey('shopper', $this->getJson(self::ORDERS)->json('data.items.0'));
    }

    public function test_placeholder_address_parts_are_null(): void
    {
        // Online gifts have no delivery address: district is stored as "-"
        $gift = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $gift->forceFill(['shipping_city' => 'riyadh', 'shipping_district' => '-'])->save();

        $item = $this->actingAs($this->customer, 'sanctum')->getJson(self::ORDERS)->assertOk()->json('data.items.0');

        $this->assertSame('riyadh', $item['city']);
        $this->assertNull($item['district']);
    }

    public function test_store_orders_search_by_order_number_store_and_product_names(): void
    {
        $roses = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $other = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $other->id, 'store_name' => 'Perfume House']);
        $perfume = $this->order(Order::STATUS_DELIVERED, '2026-09-02 10:00:00');
        $perfume->forceFill(['store_id' => $other->id])->save();
        $perfume->items()->update(['product_name' => 'Oud oil']);

        $this->assertSame([['standard', $roses->id]], $this->search(self::ORDERS, $roses->order_number));
        $this->assertSame([['standard', $perfume->id]], $this->search(self::ORDERS, 'perfume'));   // store name, any case
        $this->assertSame([['standard', $perfume->id]], $this->search(self::ORDERS, '  oud '));    // product name, trimmed
        $this->assertSame([['standard', $roses->id]], $this->search(self::ORDERS, 'roses'));
        $this->assertSame([], $this->search(self::ORDERS, '100%'));                                // wildcards are literal
    }

    public function test_store_orders_search_gift_recipients_by_name_or_phone(): void
    {
        $order = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $this->order(Order::STATUS_PENDING, '2026-09-02 10:00:00');
        Gift::query()->create([
            'order_id' => $order->id, 'sender_id' => $this->customer->id, 'store_id' => $this->store->id,
            'recipient_name' => 'Noura Ali', 'recipient_phone' => '966555123456',
            'payment_status' => 'paid', 'claim_code' => Gift::generateClaimCode(),
        ]);

        $this->assertSame([['standard', $order->id]], $this->search(self::ORDERS, 'noura'));
        $this->assertSame([['standard', $order->id]], $this->search(self::ORDERS, '0555123456'));  // local format
    }

    public function test_custom_orders_search_by_order_number_notes_items_and_shopper(): void
    {
        $shopper = User::factory()->shopper()->create(['name' => 'Huda Salem']);
        $notes   = $this->customOrder(CustomOrder::STATUS_PENDING, '2026-09-01 10:00:00', ['notes' => 'Birthday surprise for mom']);
        $item    = $this->customOrder(CustomOrder::STATUS_IN_PROGRESS, '2026-09-02 10:00:00', ['shopper_id' => $shopper->id]);
        $item->items()->update(['product_name' => 'Silver bracelet', 'description' => 'Engraved, size M']);

        $this->assertSame([['custom', $notes->id]], $this->search(self::CUSTOM_ORDERS, 'birthday'));
        $this->assertSame([['custom', $item->id]], $this->search(self::CUSTOM_ORDERS, 'bracelet'));
        $this->assertSame([['custom', $item->id]], $this->search(self::CUSTOM_ORDERS, 'engraved'));
        $this->assertSame([['custom', $item->id]], $this->search(self::CUSTOM_ORDERS, 'huda'));
        $this->assertSame([['custom', $item->id]], $this->search(self::CUSTOM_ORDERS, $item->order_number));
        $this->assertSame([], $this->search(self::CUSTOM_ORDERS, '100%'));
    }

    public function test_each_list_only_searches_its_own_order_type(): void
    {
        $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');                                                // "Red roses"
        $custom = $this->customOrder(CustomOrder::STATUS_PENDING, '2026-09-02 10:00:00', ['notes' => 'Red roses please']);

        $this->assertSame([['custom', $custom->id]], $this->search(self::CUSTOM_ORDERS, 'red roses'));
        $this->assertNotContains(['custom', $custom->id], $this->search(self::ORDERS, 'red roses'));
    }

    public function test_search_combines_with_tabs_pagination_and_ownership(): void
    {
        $active  = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $history = $this->order(Order::STATUS_DELIVERED, '2026-09-02 10:00:00');
        $this->order(Order::STATUS_PENDING, '2026-09-03 10:00:00', User::factory()->customer()->create()); // someone else's roses

        $this->assertSame([['standard', $history->id], ['standard', $active->id]], $this->search(self::ORDERS, 'roses'));
        $this->assertSame([['standard', $active->id]], $this->search(self::ORDERS, 'roses', ['tab' => 'active']));
        $this->assertSame([['standard', $history->id]], $this->search(self::ORDERS, 'roses', ['tab' => 'history']));

        $this->getJson(self::ORDERS.'?search=roses&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.id', $active->id);

        $this->getJson(self::ORDERS.'?search='.str_repeat('a', 101))->assertUnprocessable();
        $this->getJson(self::CUSTOM_ORDERS.'?search='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_tabs_split_each_list_by_status(): void
    {
        $shopper = User::factory()->shopper()->create(['name' => 'Huda']);
        ShopperProfile::factory()->approved()->create(['user_id' => $shopper->id]);

        $activeStandard  = $this->order(Order::STATUS_OUT_FOR_DELIVERY, '2026-09-01 10:00:00');
        $activeCustom    = $this->customOrder(CustomOrder::STATUS_IN_PROGRESS, '2026-09-02 10:00:00', ['shopper_id' => $shopper->id]);
        $historyStandard = $this->order(Order::STATUS_CANCELLED, '2026-09-03 10:00:00');
        $historyCustom   = $this->customOrder(CustomOrder::STATUS_COMPLETED, '2026-09-04 10:00:00', ['final_amount' => 250]);

        $this->actingAs($this->customer, 'sanctum')->getJson(self::ORDERS.'?tab=active')->assertOk()->assertJsonPath('data.filter.tab', 'active');

        $this->assertSame([['standard', $activeStandard->id]], $this->listed(self::ORDERS, ['tab' => 'active']));
        $this->assertSame([['standard', $historyStandard->id]], $this->listed(self::ORDERS, ['tab' => 'history']));
        $this->assertSame([['custom', $activeCustom->id]], $this->listed(self::CUSTOM_ORDERS, ['tab' => 'active']));
        $this->assertSame([['custom', $historyCustom->id]], $this->listed(self::CUSTOM_ORDERS, ['tab' => 'history']));

        $this->assertSame(250.0, (float) $this->getJson(self::CUSTOM_ORDERS.'?tab=history')->json('data.items.0.pricing.total.amount'));
    }

    public function test_each_list_paginates_on_its_own(): void
    {
        foreach (range(1, 5) as $day) {
            $this->order(Order::STATUS_PENDING, "2026-09-0{$day} 10:00:00");
        }
        foreach (range(1, 3) as $day) {
            $this->customOrder(CustomOrder::STATUS_PENDING, "2026-09-0{$day} 12:00:00");
        }

        $page2 = $this->actingAs($this->customer, 'sanctum')->getJson(self::ORDERS.'?per_page=4&page=2')->assertOk();
        $this->assertSame(5, $page2->json('data.pagination.total'));
        $this->assertCount(1, $page2->json('data.items'));
        $this->assertFalse($page2->json('data.pagination.has_more'));

        $this->getJson(self::CUSTOM_ORDERS.'?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.has_more', true);
    }

    public function test_invalid_tab_is_rejected(): void
    {
        $this->actingAs($this->customer, 'sanctum')->getJson(self::ORDERS.'?tab=drafts')->assertUnprocessable();
        $this->getJson(self::CUSTOM_ORDERS.'?tab=drafts')->assertUnprocessable();
    }

    public function test_custom_orders_list_requires_a_customer(): void
    {
        $this->getJson(self::CUSTOM_ORDERS)->assertUnauthorized();
        $this->actingAs(User::factory()->shopper()->create(), 'sanctum')->getJson(self::CUSTOM_ORDERS)->assertForbidden();
    }

    public function test_shows_a_standard_order_with_items_and_totals(): void
    {
        $order = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');

        $this->actingAs($this->customer, 'sanctum')
            ->getJson(self::ORDERS."/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order_type', 'standard')
            ->assertJsonPath('data.tab', 'active')
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.store.store_name', 'Rose Shop')
            ->assertJsonPath('data.items.0.name', 'Red roses')
            ->assertJsonPath('data.address.city', 'Riyadh')
            ->assertJsonPath('data.totals.total', 115);
    }

    public function test_shows_a_custom_order_with_shopper_items_and_bids(): void
    {
        $shopper = User::factory()->shopper()->create(['name' => 'Huda']);
        ShopperProfile::factory()->approved()->create(['user_id' => $shopper->id]);
        $order = $this->customOrder(CustomOrder::STATUS_ACCEPTED, '2026-09-01 10:00:00', ['shopper_id' => $shopper->id]);

        $expected = fn ($response) => $response->assertOk()
            ->assertJsonPath('data.order_type', 'custom')
            ->assertJsonPath('data.tab', 'active')
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.shopper.name', 'Huda')
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.bids', [])
            ->assertJsonPath('data.budget.min.amount', 100);

        $this->actingAs($this->customer, 'sanctum');
        $expected($this->getJson(self::CUSTOM_ORDERS."/{$order->id}"));
        $expected($this->getJson(self::ORDERS."/{$order->id}?type=custom")); // older app versions
    }

    public function test_details_only_show_the_customers_own_order_of_that_type(): void
    {
        $order   = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00');
        $foreign = $this->order(Order::STATUS_PENDING, '2026-09-01 10:00:00', User::factory()->customer()->create());
        $custom  = CustomOrder::factory()->create(['user_id' => User::factory()->customer()->create()->id]);

        $this->actingAs($this->customer, 'sanctum');

        $this->getJson(self::ORDERS."/{$order->id}?type=gift")->assertUnprocessable();
        $this->getJson(self::ORDERS."/{$foreign->id}")->assertNotFound();
        $this->getJson(self::CUSTOM_ORDERS."/{$custom->id}")->assertNotFound();
        $this->getJson(self::CUSTOM_ORDERS."/{$order->id}")->assertNotFound();  // a store order id is not a custom order
        $this->getJson(self::CUSTOM_ORDERS.'/abc')->assertNotFound();
    }
}
