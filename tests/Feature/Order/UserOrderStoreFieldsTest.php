<?php

namespace Tests\Feature\Order;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/user/orders: each card names the store it was placed with.
 */
class UserOrderStoreFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        Sanctum::actingAs($this->customer);
    }

    protected function store(string $name, ?string $logo, ?array $mainBranch): StoreProfile
    {
        $store = StoreProfile::factory()->approved()->create(['store_name' => $name, 'logo' => $logo]);

        if ($mainBranch) {
            StoreBranch::create($mainBranch + ['store_profile_id' => $store->id, 'name' => 'Main', 'is_main' => true, 'is_active' => true]);
        }

        return $store;
    }

    protected function order(StoreProfile $store): Order
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $this->customer->id, 'store_id' => $store->user_id,
            'shipping_city' => 'riyadh', 'shipping_district' => 'الياسمين', 'shipping_street' => '-',
            'shipping_building_number' => '-', 'shipping_address' => '-',
            'delivery_type' => 'instant', 'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $order->items()->create(['product_name' => 'Roses', 'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100]);

        return $order;
    }

    public function test_each_store_order_card_has_the_store_name_logo_and_address(): void
    {
        $roses = $this->order($this->store('Rose House', 'stores/logos/rose.png', ['address' => 'طريق الملك فهد، العليا', 'city' => 'riyadh']));
        $noBranch = $this->order($this->store('Cake Lab', null, null));
        $cityOnly = $this->order($this->store('Choco', 'https://cdn.example.com/choco.png', ['address' => '', 'city' => 'jeddah']));

        $items = collect($this->getJson('/api/v1/user/orders')->assertOk()->json('data.items'))->keyBy('id');

        $this->assertSame([
            'store_name' => 'Rose House',
            'store_logo' => Storage::disk('public')->url('stores/logos/rose.png'),
            'store_address' => 'طريق الملك فهد، العليا',
        ], collect($items[$roses->id])->only(['store_name', 'store_logo', 'store_address'])->all());

        // No logo or main branch: null, never a broken value
        $this->assertSame(['Cake Lab', null, null], [$items[$noBranch->id]['store_name'], $items[$noBranch->id]['store_logo'], $items[$noBranch->id]['store_address']]);

        // An absolute logo URL is kept; a branch without an address falls back to its city
        $this->assertSame('https://cdn.example.com/choco.png', $items[$cityOnly->id]['store_logo']);
        $this->assertNotNull($items[$cityOnly->id]['store_address']);
    }

    public function test_store_details_are_eager_loaded(): void
    {
        foreach (range(1, 2) as $n) {
            $this->order($this->store("Store {$n}", "logo{$n}.png", ['address' => "Street {$n}", 'city' => 'riyadh']));
        }

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/user/orders')->assertOk();

            return count(DB::getQueryLog());
        };

        $count();   // the first request also saves the customer's language
        $two = $count();

        foreach (range(3, 6) as $n) {
            $this->order($this->store("Store {$n}", "logo{$n}.png", ['address' => "Street {$n}", 'city' => 'riyadh']));
        }

        $this->assertSame($two, $count(), 'The number of queries must not grow with the number of orders');
    }

    public function test_custom_orders_have_null_store_fields(): void
    {
        CustomOrder::factory()->create(['user_id' => $this->customer->id, 'status' => CustomOrder::STATUS_PENDING]);

        $card = $this->getJson('/api/v1/user/custom-orders')->assertOk()->json('data.items.0');

        $this->assertArrayHasKey('store_name', $card);
        $this->assertSame([null, null, null], [$card['store_name'], $card['store_logo'], $card['store_address']]);
    }
}
