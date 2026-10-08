<?php

namespace Tests\Feature\Shopper;

use App\Jobs\SendPushNotification;
use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopperOrderInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests check the delivery pricing itself (with free delivery off)
        config(['checkout.free_delivery' => false]);

        $this->shopper = User::factory()->shopper()->create();
        Storage::fake('public');
        Queue::fake([SendPushNotification::class]);
        config(['custom_orders.delivery.fee' => 25]);
        config(['checkout.tax.rate' => 0]);   // VAT is covered by CustomOrderPaymentTest
    }

    protected function order(string $status = CustomOrder::STATUS_IN_PROGRESS, ?User $shopper = null): CustomOrder
    {
        $order = CustomOrder::factory()->assignedTo($shopper ?? $this->shopper)->create(['status' => $status]);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id, 'product_name' => 'Oud perfume 100ml', 'quantity' => 2, 'sort_order' => 0]);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id, 'product_name' => 'Rose bouquet', 'quantity' => 1, 'sort_order' => 1]);

        return $order;
    }

    protected function address(User $owner, array $attributes = []): UserAddress
    {
        return UserAddress::create($attributes + [
            'user_id' => $owner->id, 'location_name' => 'Home', 'city' => 'Riyadh', 'district' => 'Al Olaya',
            'street' => 'King Fahd Rd', 'building_number' => '7', 'phone' => '966500000001',
            'latitude' => 24.7136, 'longitude' => 46.6753,
        ]);
    }

    protected function submit(CustomOrder $order, array $overrides = [])
    {
        Sanctum::actingAs($this->shopper);
        [$first, $second] = $order->items()->orderBy('sort_order')->get();

        return $this->post("/api/v1/shopper/orders/{$order->id}/submit-invoice-prices", array_merge([
            'invoice_image'    => UploadedFile::fake()->image('invoice.jpg'),
            'pickup_address'   => [
                'location_name' => ' Panorama Mall ', 'city' => 'Riyadh', 'district' => 'Al Mursalat',
                'street' => 'Takhassusi St', 'building_number' => '3120', 'latitude' => 24.6922, 'longitude' => 46.6697,
            ],
            'shopper_fees'     => 30,
            'items'            => [
                ['id' => $first->id, 'unit_price' => 150],
                ['id' => $second->id, 'unit_price' => '75.50'],
            ],
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_shopper_submits_the_invoice_and_actual_prices_and_the_order_is_priced(): void
    {
        $order    = $this->order();
        $delivery = $this->address($order->user);
        $order->forceFill(CustomOrder::deliveryAddressAttributes($delivery, $order->user))->save();

        $response = $this->submit($order)->assertOk()
            ->assertJsonPath('message', __('custom_orders.invoice_submitted'))
            ->assertJsonPath('data.id', $order->id)
            // A new pickup address saved for the shopper...
            ->assertJsonPath('data.pickup.location_name', 'Panorama Mall')
            ->assertJsonPath('data.pickup.full_address', '3120, Takhassusi St, Al Mursalat, Riyadh')
            ->assertJsonPath('data.pickup.phone', $this->shopper->phone)
            ->assertJsonPath('data.pickup.latitude', 24.6922)
            // ...while the customer's delivery address is untouched
            ->assertJsonPath('data.delivery.address_id', $delivery->id)
            ->assertJsonPath('data.delivery.full_address', '7, King Fahd Rd, Al Olaya, Riyadh')
            ->assertJsonPath('data.items.0.unit_price.amount', 150)
            ->assertJsonPath('data.items.0.total_price.amount', 300)
            ->assertJsonPath('data.items.1.unit_price.amount', 75.5)
            // Computed on the server: 150 x 2 + 75.50 = 375.50 + 30 fees = 405.50, + 25 delivery
            ->assertJsonPath('data.pricing.subtotal.amount', 375.5)
            ->assertJsonPath('data.pricing.shopper_fees.amount', 30)
            ->assertJsonPath('data.pricing.final_amount.amount', 405.5)
            ->assertJsonPath('data.pricing.delivery_fee.amount', 25)
            ->assertJsonPath('data.pricing.total.amount', 430.5)
            ->assertJsonPath('data.final_amount.amount', 405.5);

        $order->refresh();
        Storage::disk('public')->assertExists($order->invoice_path);
        $this->assertStringContainsString($order->invoice_path, $response->json('data.invoice.image_url'));
        $this->assertNotNull($order->invoice_submitted_at);
        $this->assertEquals(405.5, $order->final_amount);
        $this->assertEquals(430.5, $order->total_amount);
        $pickup = $this->shopper->addresses()->sole();
        $this->assertSame($pickup->id, $order->pickup_address_id);
        $this->assertTrue($pickup->is_default);   // the shopper's first address, as with POST /addresses
        $this->assertSame($delivery->id, $order->delivery_address_id);
        $this->assertSame($delivery->id, $order->deliveryAddress->id);
        $this->assertSame($pickup->id, $order->pickupAddress->id);
        $this->assertSame('waiting_for_payment', $order->status);   // the invoice means the items were bought
    }

    public function test_resubmitting_replaces_the_invoice_and_items_left_out_keep_their_price(): void
    {
        $order = $this->order(CustomOrder::STATUS_WAITING_FOR_PAYMENT);
        $this->submit($order)->assertOk();
        $old = $order->fresh()->invoice_path;

        // item_id / actual_price are accepted for id / unit_price; the saved pickup address is reused
        [$first, $second] = $order->items()->orderBy('sort_order')->get();
        $pickup = $order->fresh()->pickup_address_id;
        $this->submit($order, [
            'pickup_address' => null, 'pickup_address_id' => $pickup,
            'shopper_fees' => 0, 'items' => [['item_id' => $first->id, 'actual_price' => 100]],
        ])->assertOk()
            ->assertJsonPath('data.pickup.id', $pickup)
            ->assertJsonPath('data.items.0.unit_price.amount', 100)
            ->assertJsonPath('data.items.1.unit_price.amount', 75.5)       // kept
            ->assertJsonPath('data.pricing.final_amount.amount', 275.5)     // 100 x 2 + 75.50, no fees
            ->assertJsonPath('data.pricing.total.amount', 300.5);

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($order->fresh()->invoice_path);
        $this->assertSame(1, $this->shopper->addresses()->count());   // no duplicate created
    }

    public function test_input_is_validated(): void
    {
        $order = $this->order();
        $other = $this->order()->items()->first();

        $this->submit($order, ['invoice_image' => null, 'pickup_address' => null, 'shopper_fees' => -1, 'items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_image', 'pickup_address_id', 'pickup_address', 'shopper_fees', 'items'], 'data.errors')
            ->assertJsonPath('data.errors.pickup_address_id.0', __('custom_orders.pickup_address_required'));

        // A new pickup address needs every column; coordinates come in pairs
        $this->submit($order, ['pickup_address' => ['city' => 'Riyadh', 'latitude' => 24.7]])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'pickup_address.location_name', 'pickup_address.district', 'pickup_address.street',
                'pickup_address.building_number', 'pickup_address.longitude',
            ], 'data.errors');

        // A saved one must be the shopper's own: not the customer's, nor anyone else's
        $mine = $this->address($this->shopper);
        foreach ([$this->address($order->user), $this->address(User::factory()->shopper()->create()), 999999] as $address) {
            $this->submit($order, ['pickup_address' => null, 'pickup_address_id' => $address->id ?? $address])->assertUnprocessable()
                ->assertJsonPath('data.errors.pickup_address_id.0', __('custom_orders.address_not_found'));
        }

        // Not both at once
        $this->submit($order, ['pickup_address_id' => $mine->id])->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_address_id', 'data.errors');
        $this->assertSame(1, $this->shopper->addresses()->count());

        $this->submit($order, ['invoice_image' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_image', 'data.errors');
        $this->submit($order, ['invoice_image' => UploadedFile::fake()->image('big.jpg')->size(3000)])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_image', 'data.errors');

        $errors = $this->submit($order, ['items' => [['id' => $other->id, 'unit_price' => 10], ['id' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.id', 'items.1.id', 'items.1.unit_price'], 'data.errors')
            ->json('data.errors');
        $this->assertSame(__('custom_orders.item_not_in_order'), $errors['items.0.id'][0]);

        $this->assertNull($order->fresh()->invoice_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_in_progress_or_completed_orders_of_this_shopper_accept_an_invoice(): void
    {
        foreach ([CustomOrder::STATUS_PENDING, CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_CANCELLED] as $status) {
            $this->submit($this->order($status))->assertStatus(409)->assertJsonPath('data.current_status', $status);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());   // upload removed again

        $this->submit($this->order(shopper: User::factory()->shopper()->create()))->assertNotFound();

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->post("/api/v1/shopper/orders/{$this->order()->id}/submit-invoice-prices", [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_final_amount_is_computed_and_every_item_must_be_priced(): void
    {
        $order = $this->order();
        [$first, $second] = $order->items()->orderBy('sort_order')->get();

        // The second item has no price yet: nothing is saved
        $this->submit($order, ['items' => [['id' => $first->id, 'unit_price' => 150]]])->assertUnprocessable()
            ->assertJsonPath('data.errors.items.0', __('custom_orders.items_unpriced'));
        $this->assertNull($first->fresh()->unit_price);
        $this->assertNull($order->fresh()->final_amount);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, $this->shopper->addresses()->count());

        // A final amount sent by the client is ignored; 0 marks an item not bought
        $this->submit($order, ['final_amount' => 1, 'shopper_fees' => 20, 'items' => [
            ['id' => $first->id, 'unit_price' => 150],
            ['id' => $second->id, 'unit_price' => 0],
        ]])->assertOk()
            ->assertJsonPath('data.final_amount.amount', 320)            // 150 x 2 + 0 + 20
            ->assertJsonPath('data.pricing.total.amount', 345);
    }
}
