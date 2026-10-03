<?php

namespace Tests\Feature\Shopper;

use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use App\Models\CustomOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopperOrderShowTest extends TestCase
{
    use RefreshDatabase;

    protected User $shopper;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopper  = User::factory()->shopper()->create();
        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed', 'phone' => '966555000111']);
    }

    protected function order(array $attributes = [], ?User $shopper = null): CustomOrder
    {
        return CustomOrder::factory()->assignedTo($shopper ?? $this->shopper)->create($attributes + ['user_id' => $this->customer->id]);
    }

    public function test_shows_the_order_with_customer_delivery_items_media_and_alternatives(): void
    {
        $order = $this->order([
            'status' => CustomOrder::STATUS_ACCEPTED, 'accepted_at' => now(),
            'notes' => 'Gift for mom', 'confirmation_notes' => 'Call before arriving',
            'delivery_city' => 'Riyadh', 'delivery_district' => 'Olaya', 'delivery_street' => 'King Fahd Rd',
            'delivery_address' => '12, King Fahd Rd, Olaya, Riyadh', 'budget_min' => 300, 'budget_max' => 500,
        ]);
        $perfume = CustomOrderItem::factory()->create([
            'custom_order_id' => $order->id, 'product_name' => 'Oud perfume', 'description' => '100ml, black edition',
            'quantity' => 2, 'expected_price_min' => 150, 'expected_price_max' => 250, 'sort_order' => 0,
        ]);
        $perfume->media()->create(['disk' => 'public', 'path' => 'custom-orders/ref.jpg', 'mime_type' => 'image/jpeg', 'size' => 1024]);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id, 'sort_order' => 1]);
        CustomOrderAlternative::query()->create([
            'custom_order_id' => $order->id, 'custom_order_item_id' => $perfume->id, 'shopper_id' => $this->shopper->id,
            'product_name' => 'Oud perfume 50ml', 'price' => 180, 'reason' => 'Size not available',
        ]);

        Sanctum::actingAs($this->shopper);
        $data = $this->getJson("/api/v1/shopper/orders/{$order->id}")->assertOk()
            ->assertJsonPath('message', __('messages.success'))
            ->json('data');

        $this->assertSame([$order->id, $order->order_number, 'accepted', 'accepted', 'active'],
            [$data['id'], $data['order_number'], $data['status'], $data['status_badge']['key'], $data['tab']]);
        $this->assertSame(['next_status' => 'in_progress', 'can_suggest_alternatives' => true, 'can_cancel' => true, 'can_submit_invoice' => false], $data['actions']);
        $this->assertSame(['Sara Ahmed', '966555000111'], [$data['customer']['name'], $data['customer']['phone']]);

        $this->assertSame(['Riyadh', 'Olaya', 'King Fahd Rd', '12, King Fahd Rd, Olaya, Riyadh', 'Call before arriving'], [
            $data['delivery']['city'], $data['delivery']['district'], $data['delivery']['street'],
            $data['delivery']['full_address'], $data['delivery']['notes'],
        ]);
        $this->assertSame('Gift for mom', $data['notes']);
        $this->assertSame('300.00 - 500.00 SAR', $data['budget']['label']);
        $this->assertNotNull($data['timeline']['accepted_at']);

        $this->assertSame(2, $data['items_count']);
        $item = $data['items'][0];
        $this->assertSame(['Oud perfume', '100ml, black edition', 2], [$item['product_name'], $item['description'], $item['quantity']]);
        $this->assertEquals([150, 250], [$item['expected_price']['min']['amount'], $item['expected_price']['max']['amount']]);
        $this->assertEquals([300, 500], [$item['expected_total']['min']['amount'], $item['expected_total']['max']['amount']]);
        $this->assertCount(1, $item['images']);
        $this->assertStringEndsWith('custom-orders/ref.jpg', $item['images'][0]['url']);
        $this->assertSame('Oud perfume 50ml', $item['alternatives'][0]['product_name']);
    }

    public function test_finished_orders_have_no_next_step(): void
    {
        $order = $this->order(['status' => CustomOrder::STATUS_CANCELLED, 'cancelled_by' => 'customer', 'cancellation_reason' => 'Changed my mind']);

        Sanctum::actingAs($this->shopper);
        $this->getJson("/api/v1/shopper/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.tab', 'history')
            ->assertJsonPath('data.actions', ['next_status' => null, 'can_suggest_alternatives' => false, 'can_cancel' => false, 'can_submit_invoice' => false])
            ->assertJsonPath('data.cancellation', ['by' => 'customer', 'reason' => 'Changed my mind']);
    }

    public function test_only_the_assigned_shopper_can_view_a_confirmed_order(): void
    {
        $others = $this->order([], User::factory()->shopper()->create());
        $draft  = $this->order(['status' => CustomOrder::STATUS_DRAFT]);
        $mine   = $this->order();

        $this->getJson("/api/v1/shopper/orders/{$mine->id}")->assertUnauthorized();

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/shopper/orders/{$mine->id}")->assertForbidden();

        Sanctum::actingAs($this->shopper);
        $this->getJson("/api/v1/shopper/orders/{$others->id}")->assertNotFound();
        $this->getJson("/api/v1/shopper/orders/{$draft->id}")->assertNotFound();
        $this->getJson('/api/v1/shopper/orders/999999')->assertNotFound();
        $this->getJson("/api/v1/shopper/orders/{$mine->id}")->assertOk();
    }
}
