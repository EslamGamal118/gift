<?php

namespace Tests\Feature\Checkout;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /addresses serves customers (delivery addresses) and personal shoppers
 * (pickup addresses), each scoped to the signed-in account.
 */
class AddressAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'location_name' => 'Panorama Mall', 'city' => 'Riyadh', 'district' => 'Al Mursalat',
            'street' => 'Takhassusi St', 'building_number' => '3120', 'latitude' => 24.6922, 'longitude' => 46.6753,
        ];
    }

    public function test_a_shopper_manages_their_own_addresses(): void
    {
        $shopper = User::factory()->shopper()->create();
        $other   = UserAddress::create(['user_id' => User::factory()->customer()->create()->id] + $this->payload(['location_name' => 'Home']));
        Sanctum::actingAs($shopper);

        $first = $this->postJson('/api/v1/addresses', $this->payload())->assertCreated()
            ->assertJsonPath('data.location_name', 'Panorama Mall')
            ->assertJsonPath('data.is_default', true)                 // the first one
            ->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload(['location_name' => 'Riyadh Park']))->assertCreated()
            ->assertJsonPath('data.is_default', false)
            ->json('data.id');

        $this->assertSame([$shopper->id], UserAddress::query()->whereIn('id', [$first, $second])->distinct()->pluck('user_id')->all());

        // Only their own, default first
        $this->getJson('/api/v1/addresses')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first);

        $this->getJson("/api/v1/addresses/{$second}")->assertOk();
        $this->putJson("/api/v1/addresses/{$second}", ['street' => 'Olaya St'])->assertOk()->assertJsonPath('data.street', 'Olaya St');
        $this->postJson("/api/v1/addresses/{$second}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->deleteJson("/api/v1/addresses/{$first}")->assertOk();
        $this->assertNull(UserAddress::find($first));

        // Someone else's address is not found
        $this->getJson("/api/v1/addresses/{$other->id}")->assertNotFound();
        $this->putJson("/api/v1/addresses/{$other->id}", ['street' => 'X St'])->assertNotFound();
        $this->deleteJson("/api/v1/addresses/{$other->id}")->assertNotFound();
        $this->assertSame('Takhassusi St', $other->fresh()->street);
    }

    public function test_an_address_saved_by_the_shopper_can_be_the_pickup_address_of_their_order(): void
    {
        Storage::fake('public');
        $shopper = User::factory()->shopper()->create();
        $order   = CustomOrder::factory()->assignedTo($shopper)->create(['status' => CustomOrder::STATUS_IN_PROGRESS]);
        $item    = CustomOrderItem::factory()->create(['custom_order_id' => $order->id]);
        Sanctum::actingAs($shopper);

        $address = $this->postJson('/api/v1/addresses', $this->payload())->assertCreated()->json('data.id');

        $this->post("/api/v1/shopper/orders/{$order->id}/submit-invoice-prices", [
            'invoice_image'     => UploadedFile::fake()->image('invoice.jpg'),
            'pickup_address_id' => $address,
            'shopper_fees'      => 10,
            'final_amount'      => 100,
            'items'             => [['id' => $item->id, 'unit_price' => 50]],
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.pickup.id', $address);
    }

    public function test_customers_keep_access_and_other_account_types_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson('/api/v1/addresses', $this->payload())->assertCreated();
        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(1, 'data');

        foreach ([User::factory()->captain()->create(), User::factory()->storeOwner()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/addresses')->assertForbidden();
            $this->postJson('/api/v1/addresses', $this->payload())->assertForbidden();
        }

        // The rest of the customer flow stays customer-only
        Sanctum::actingAs(User::factory()->shopper()->create());
        $this->getJson('/api/v1/cart')->assertForbidden();
    }
}
