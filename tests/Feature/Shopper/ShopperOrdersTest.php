<?php

namespace Tests\Feature\Shopper;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopperOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/api/v1/shopper/orders';

    protected User $shopper;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopper  = User::factory()->shopper()->create();
        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed']);
    }

    protected function order(string $status, string $createdAt, array $attributes = []): CustomOrder
    {
        $order = CustomOrder::factory()->assignedTo($this->shopper)->create($attributes + ['user_id' => $this->customer->id, 'status' => $status]);
        CustomOrderItem::factory()->count(2)->create(['custom_order_id' => $order->id]);
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order;
    }

    /**
     * @return list<int>
     */
    protected function ids(array $query = []): array
    {
        Sanctum::actingAs($this->shopper);

        return array_column($this->getJson(self::URL.($query ? '?'.http_build_query($query) : ''))->assertOk()->json('data.items'), 'id');
    }

    public function test_lists_only_the_shoppers_non_draft_orders_with_the_card_fields(): void
    {
        $order = $this->order(CustomOrder::STATUS_PENDING, '2026-09-02 10:00:00', [
            'budget_min' => 150, 'budget_max' => 300, 'delivery_city' => 'Riyadh', 'delivery_district' => 'Olaya',
        ]);
        $this->order(CustomOrder::STATUS_DRAFT, '2026-09-03 10:00:00');                                     // not confirmed yet
        CustomOrder::factory()->assignedTo(User::factory()->shopper()->create())->create();                 // another shopper's
        CustomOrder::factory()->bidding()->create();                                                        // open for bids

        Sanctum::actingAs($this->shopper);
        $response = $this->getJson(self::URL, ['Accept-Language' => 'ar'])->assertOk();

        $response->assertJsonPath('data.pagination.total', 1);
        $card = $response->json('data.items.0');

        $this->assertSame($order->id, $card['id']);
        $this->assertSame(['key' => 'new', 'label' => 'جديد'], $card['status_badge']);
        $this->assertSame('active', $card['tab']);
        $this->assertSame('Sara Ahmed', $card['customer']['name']);
        $this->assertSame(2, $card['items_count']);
        $this->assertSame('150.00 - 300.00 ر.س', $card['budget']['label']);
        $this->assertSame(['Riyadh', 'Olaya'], [$card['delivery']['city'], $card['delivery']['district']]);
        $this->assertSame($order->delivery_address, $card['delivery']['address']);
    }

    public function test_tabs_split_active_and_history(): void
    {
        $new        = $this->order(CustomOrder::STATUS_PENDING, '2026-09-01 10:00:00');
        $inProgress = $this->order(CustomOrder::STATUS_IN_PROGRESS, '2026-09-02 10:00:00');
        $completed  = $this->order(CustomOrder::STATUS_COMPLETED, '2026-09-03 10:00:00');
        $cancelled  = $this->order(CustomOrder::STATUS_CANCELLED, '2026-09-04 10:00:00');

        $this->assertSame([$cancelled->id, $completed->id, $inProgress->id, $new->id], $this->ids());
        $this->assertSame([$inProgress->id, $new->id], $this->ids(['tab' => 'active']));
        $this->assertSame([$cancelled->id, $completed->id], $this->ids(['tab' => 'history']));
    }

    public function test_status_filters_within_the_tab_and_accepts_aliases(): void
    {
        $new      = $this->order(CustomOrder::STATUS_PENDING, '2026-09-01 10:00:00');
        $accepted = $this->order(CustomOrder::STATUS_ACCEPTED, '2026-09-02 10:00:00');
        $done     = $this->order(CustomOrder::STATUS_COMPLETED, '2026-09-03 10:00:00');

        $this->assertSame([$new->id], $this->ids(['status' => 'new']));
        $this->assertSame([$new->id], $this->ids(['status' => 'pending']));                 // stored name
        $this->assertSame([$accepted->id], $this->ids(['status' => 'confirmed']));          // app wording
        $this->assertSame([$accepted->id], $this->ids(['tab' => 'active', 'status' => 'accepted']));
        $this->assertSame([$done->id], $this->ids(['tab' => 'history', 'status' => 'COMPLETED']));
        $this->assertSame([], $this->ids(['tab' => 'history', 'status' => 'new']));         // not in that tab

        $this->getJson(self::URL.'?status=draft')->assertUnprocessable();
        $this->getJson(self::URL.'?tab=drafts')->assertUnprocessable();
    }

    public function test_search_by_order_number_customer_recipient_items_and_notes(): void
    {
        $byNumber = $this->order(CustomOrder::STATUS_PENDING, '2026-09-01 10:00:00');
        $byNotes  = $this->order(CustomOrder::STATUS_ACCEPTED, '2026-09-02 10:00:00', ['notes' => 'Wrap it in gold paper']);
        $byItem   = $this->order(CustomOrder::STATUS_COMPLETED, '2026-09-03 10:00:00', ['delivery_name' => 'Noura', 'delivery_phone' => '966555123456']);
        $byItem->items()->first()->update(['product_name' => 'Oud perfume']);

        $other = User::factory()->customer()->create(['name' => 'Khalid Omar']);
        $byCustomer = $this->order(CustomOrder::STATUS_PENDING, '2026-09-04 10:00:00', ['user_id' => $other->id]);

        $this->assertSame([$byNumber->id], $this->ids(['search' => $byNumber->order_number]));
        $this->assertSame([$byNotes->id], $this->ids(['search' => 'gold']));
        $this->assertSame([$byItem->id], $this->ids(['search' => 'oud']));
        $this->assertSame([$byItem->id], $this->ids(['search' => 'noura']));               // recipient
        $this->assertSame([$byItem->id], $this->ids(['search' => '0555123456']));          // recipient phone, local format
        $this->assertSame([$byCustomer->id], $this->ids(['search' => 'khalid']));          // customer name
        $this->assertSame([$byItem->id], $this->ids(['search' => 'oud', 'tab' => 'history']));
        $this->assertSame([], $this->ids(['search' => 'oud', 'tab' => 'active']));
        $this->assertSame([], $this->ids(['search' => '100%']));
    }

    public function test_counts_follow_the_search_and_pagination_is_kept(): void
    {
        foreach (range(1, 3) as $day) {
            $this->order(CustomOrder::STATUS_PENDING, "2026-09-0{$day} 10:00:00", ['notes' => 'roses']);
        }
        $this->order(CustomOrder::STATUS_COMPLETED, '2026-09-04 10:00:00', ['notes' => 'roses']);
        $this->order(CustomOrder::STATUS_CANCELLED, '2026-09-05 10:00:00', ['notes' => 'tulips']);

        Sanctum::actingAs($this->shopper);

        $response = $this->getJson(self::URL.'?search=roses&tab=active&per_page=2&page=2')->assertOk();
        $response->assertJsonPath('data.filters', ['tab' => 'active', 'status' => null, 'search' => 'roses'])
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.counts.active', 3)
            ->assertJsonPath('data.counts.history', 1)
            ->assertJsonPath('data.counts.all', 4)
            ->assertJsonPath('data.counts.by_status', ['new' => 3, 'accepted' => 0, 'in_progress' => 0, 'waiting_for_alternative' => 0, 'waiting_for_payment' => 0, 'completed' => 1, 'cancelled' => 0]);
    }

    public function test_only_shoppers_can_list(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        Sanctum::actingAs($this->customer);
        $this->getJson(self::URL)->assertForbidden();
    }
}
