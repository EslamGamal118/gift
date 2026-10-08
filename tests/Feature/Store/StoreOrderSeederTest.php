<?php

namespace Tests\Feature\Store;

use App\Models\AppNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOrderSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_paid_orders_in_every_status_that_the_store_can_see(): void
    {
        $store = User::factory()->storeOwner()->create(['phone' => '966512223334']);
        StoreProfile::factory()->approved()->create(['user_id' => $store->id]);

        $this->artisan('orders:demo', ['store' => $store->phone, '--count' => 14])->assertSuccessful();

        $orders = Order::query()->forStore($store->id)->with(['items', 'statusHistories'])->get();

        $this->assertCount(14, $orders);
        $this->assertSame(14, Order::query()->forStore($store->id)->visibleToStore()->count());
        // Every status the store moves an order through (the delivery company's own come from its webhook)
        $this->assertEqualsCanonicalizing(array_values(array_diff(array_keys(Order::STORE_BADGES), Order::DELIVERY_STATUSES)), $orders->pluck('status')->unique()->values()->all());
        $this->assertTrue(Product::query()->where('store_id', $store->id)->exists());

        foreach ($orders as $order) {
            $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
            $this->assertNotEmpty($order->items);
            $this->assertEquals(round($order->items->sum('subtotal'), 2), (float) $order->subtotal);
            $this->assertEquals(
                round($order->subtotal - $order->discount_amount + $order->delivery_fee + $order->express_fee + $order->tax_amount, 2),
                (float) $order->total_amount,
            );
            $this->assertSame($order->status, $order->statusHistories->last()->to_status);
            $this->assertNotNull($order->user->avatar);
        }

        $this->actingAs($store, 'sanctum')->getJson('/api/v1/store/orders?per_page=50')->assertOk()->assertJsonCount(14, 'data.items');
    }

    public function test_fresh_replaces_only_demo_orders(): void
    {
        $store = User::factory()->storeOwner()->create(['phone' => '966512223335']);
        StoreProfile::factory()->approved()->create(['user_id' => $store->id]);

        $this->artisan('orders:demo', ['store' => $store->id, '--count' => 5])->assertSuccessful();

        $real = Order::query()->forStore($store->id)->first()->replicate(['order_number']);
        $real->forceFill(['order_number' => Order::generateNumber(), 'payment_data' => ['reference' => 'REAL-1']])->save();

        $this->artisan('orders:demo', ['store' => $store->id, '--count' => 3, '--fresh' => true])->assertSuccessful();

        $this->assertSame(4, Order::query()->forStore($store->id)->count());
        $this->assertTrue(Order::query()->whereKey($real->id)->exists());

        // Only the current run's new orders have a (stored, unread) bell notification
        $this->assertSame(
            Order::query()->forStore($store->id)->where('payment_data->demo', true)->where('status', Order::STATUS_PENDING)->count(),
            AppNotification::query()->where('notifiable_id', $store->id)->unread()->count(),
        );
    }

    public function test_it_rejects_unknown_stores_and_bad_counts(): void
    {
        $this->artisan('orders:demo', ['store' => '966500000999'])->assertFailed();

        $store = User::factory()->storeOwner()->create();
        $this->artisan('orders:demo', ['store' => $store->id, '--count' => 0])->assertFailed();
    }
}
