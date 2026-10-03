<?php

namespace Tests\Feature\CustomOrder;

use App\Models\AppNotification;
use App\Models\Category;
use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\CustomOrderItemMedia;
use App\Models\DeliverySlot;
use App\Models\ShopperProfile;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomOrderTest extends TestCase
{
    use RefreshDatabase;

    protected const LAT = 24.7136;
    protected const LNG = 46.6753;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->customer = User::factory()->customer()->create(['name' => 'Sara Al-Ahmad', 'phone' => '966500000001']);
    }

    protected function shopper(array $profile = [], array $user = []): User
    {
        $account = User::factory()->shopper()->create($user);
        ShopperProfile::factory()->approved()->create(['user_id' => $account->id] + $profile);

        return $account;
    }

    protected function address(array $attributes = []): UserAddress
    {
        return UserAddress::create([
            'user_id' => $this->customer->id, 'is_default' => true, 'location_name' => 'Home',
            'city' => 'Riyadh', 'district' => 'Olaya', 'street' => 'King Fahd Rd', 'building_number' => '12',
            'latitude' => self::LAT, 'longitude' => self::LNG,
        ] + $attributes);
    }

    protected function slot(string $start = '09:00:00', string $end = '11:00:00'): DeliverySlot
    {
        return DeliverySlot::create([
            'label' => ['en' => 'Morning', 'ar' => 'صباحًا'], 'period' => 'morning',
            'start_time' => $start, 'end_time' => $end, 'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'items' => [
                ['product_name' => 'Chanel No. 5', 'description' => '100ml, sealed', 'quantity' => 1, 'expected_price_min' => 400, 'expected_price_max' => 550],
                ['product_name' => 'Patchi chocolate', 'quantity' => 2, 'expected_price_min' => 80, 'expected_price_max' => 120],
            ],
            'delivery_at' => now()->addDay()->format('Y-m-d H:i'),
            'notes'       => 'Gift wrap please',
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | POST /custom-orders
    |--------------------------------------------------------------------------
    */

    public function test_creates_a_draft_with_items_images_and_saved_address(): void
    {
        $address = $this->address();
        $images  = [UploadedFile::fake()->image('ref1.jpg', 400, 400), UploadedFile::fake()->image('ref2.png', 300, 300)];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->post('/api/v1/custom-orders', $this->payload([
                'address_id' => $address->id,
                'items'      => [['images' => $images]],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $response->assertJsonPath('status', 201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.status_label', 'Draft')
            ->assertJsonPath('data.can_assign', true)
            ->assertJsonPath('data.can_confirm', false)                 // no shopper chosen yet
            ->assertJsonPath('data.shopper', null)
            ->assertJsonPath('data.address.id', $address->id)
            ->assertJsonPath('data.address.city', 'Riyadh')
            ->assertJsonPath('data.address.full_address', '12, King Fahd Rd, Olaya, Riyadh')
            ->assertJsonPath('data.address.phone', '966500000001')   // falls back to the customer's phone
            ->assertJsonPath('data.address.latitude', self::LAT)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_name', 'Chanel No. 5')
            ->assertJsonPath('data.items.0.expected_price.max.amount', 550)
            ->assertJsonPath('data.items.1.quantity', 2)
            ->assertJsonPath('data.items.1.expected_total.max.amount', 240)
            ->assertJsonCount(2, 'data.items.0.images')
            ->assertJsonCount(0, 'data.items.1.images')
            // budget = sum of the item ranges: 400 + 2*80 .. 550 + 2*120
            ->assertJsonPath('data.budget.min.amount', 560)
            ->assertJsonPath('data.budget.max.amount', 790)
            ->assertJsonPath('data.budget.label', '560.00 - 790.00 SAR')
            ->assertJsonStructure(['data' => [
                'id', 'order_number', 'status', 'assignment', 'address', 'delivery_at', 'budget', 'items', 'timeline',
            ]]);

        $this->assertStringStartsWith('CO-', $response->json('data.order_number'));
        $this->assertDatabaseCount('custom_order_items', 2);
        $this->assertDatabaseCount('custom_order_item_media', 2);

        CustomOrderItemMedia::all()->each(fn ($m) => Storage::disk('public')->assertExists($m->path));
    }

    public function test_creates_with_inline_address_and_records_the_chosen_shopper(): void
    {
        $shopper = $this->shopper();

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/custom-orders', $this->payload([
                'shopper_id' => $shopper->id,
                'budget_min' => 500,
                'budget_max' => 900,
                'address'    => [
                    'city' => 'Jeddah', 'district' => 'Al Rawdah', 'street' => 'Tahlia', 'building_number' => '7',
                    'phone' => '0551234567', 'latitude' => 21.5, 'longitude' => 39.2,
                ],
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.assignment.mode', 'direct')
            ->assertJsonPath('data.shopper.name', $shopper->name)
            ->assertJsonPath('data.can_confirm', true)
            ->assertJsonPath('data.address.id', null)
            ->assertJsonPath('data.address.city', 'Jeddah')
            ->assertJsonPath('data.address.phone', '966551234567')
            ->assertJsonPath('data.budget.min.amount', 500)
            ->assertJsonPath('data.budget.max.amount', 900);

        // Not submitted until the customer confirms (step 3)
        $this->assertNull($response->json('data.timeline.submitted_at'));
        $this->assertNull($response->json('data.timeline.assigned_at'));
        $this->assertSame(0, AppNotification::query()->where('notifiable_id', $shopper->id)->count());
    }

    public function test_creates_a_draft_without_an_address(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/custom-orders', $this->payload(['delivery_at' => null]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.delivery_at', null);
    }

    public function test_validates_items_budgets_address_and_delivery_time(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/custom-orders', [
                'items' => [
                    ['product_name' => 'X', 'expected_price_min' => 100, 'expected_price_max' => 50],
                ],
                'budget_min'  => 300,
                'budget_max'  => 100,
                'delivery_at' => now()->addMinutes(5)->format('Y-m-d H:i'),
            ])
            ->assertUnprocessable();

        $errors = $response->json('data.errors');

        $this->assertArrayHasKey('items.0.product_name', $errors);
        $this->assertArrayHasKey('items.0.expected_price_max', $errors);
        $this->assertArrayHasKey('budget_max', $errors);
        $this->assertArrayHasKey('delivery_at', $errors);
        $this->assertArrayNotHasKey('address_id', $errors);              // the address comes with confirm
        $this->assertSame(__('custom_orders.price_range_invalid'), $errors['items.0.expected_price_max'][0]);

        // Someone else's address cannot be used
        $foreign = UserAddress::create([
            'user_id' => User::factory()->customer()->create()->id, 'location_name' => 'Work',
            'city' => 'Riyadh', 'district' => 'x', 'street' => 'y', 'building_number' => '1',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/custom-orders', $this->payload(['address_id' => $foreign->id]))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['address_id']]]);

        // Non-image uploads are rejected
        $this->actingAs($this->customer, 'sanctum')
            ->post('/api/v1/custom-orders', $this->payload([
                'address_id' => $this->address()->id,
                'items' => [['images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]]],
            ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['items.0.images.0']]]);

        $this->assertDatabaseCount('custom_orders', 0);
    }

    public function test_rejects_a_shopper_that_is_not_available(): void
    {
        $busy = $this->shopper(['is_available' => false]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/custom-orders', $this->payload(['address_id' => $this->address()->id, 'shopper_id' => $busy->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('custom_orders.shopper_unavailable'));

        // Nothing half-written
        $this->assertDatabaseCount('custom_orders', 0);
        $this->assertDatabaseCount('custom_order_items', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /custom-orders/shoppers
    |--------------------------------------------------------------------------
    */

    public function test_lists_visible_shoppers_with_rating_completed_orders_specialties_and_distance(): void
    {
        $perfumes = Category::factory()->create(['name' => ['en' => 'Perfumes', 'ar' => 'عطور']]);

        $near = $this->shopper(['rating_avg' => 4.8, 'rating_count' => 40, 'latitude' => self::LAT + 0.02, 'longitude' => self::LNG, 'bio' => 'Luxury gifts'], ['name' => 'Near Shopper']);
        $far  = $this->shopper(['rating_avg' => 4.9, 'rating_count' => 10, 'latitude' => self::LAT + 0.5, 'longitude' => self::LNG], ['name' => 'Far Shopper']);
        $busy = $this->shopper(['is_available' => false, 'latitude' => self::LAT, 'longitude' => self::LNG], ['name' => 'Busy Shopper']);
        $this->shopper(['status' => 'pending']);                                              // not approved
        $this->shopper([], ['status' => 'blocked']);                                           // blocked account
        $near->shopperProfile->categories()->attach($perfumes->id);

        CustomOrder::factory()->count(3)->completed()->create(['shopper_id' => $near->id]);
        CustomOrder::factory()->assignedTo($near)->create();                                   // active, not counted

        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders/shoppers?latitude='.self::LAT.'&longitude='.self::LNG)
            ->assertOk();

        $items = $response->json('data.items');

        // Available first, then nearest
        $this->assertSame([$near->id, $far->id, $busy->id], array_column($items, 'id'));
        $this->assertSame('Near Shopper', $items[0]['name']);
        $this->assertSame(4.8, $items[0]['rating']['average']);
        $this->assertSame(3, $items[0]['completed_orders_count']);
        $this->assertSame('3 completed orders', $items[0]['completed_orders_label']);
        $this->assertSame('Perfumes', $items[0]['specialties'][0]['name']);
        $this->assertSame('2.2 km', $items[0]['distance']['label']);
        $this->assertTrue($items[0]['is_available']);
        $this->assertSame('Busy', $items[2]['availability']['label']);
        $this->assertArrayNotHasKey('iban', $items[0]);
        $response->assertJsonPath('data.location.source', 'gps')->assertJsonPath('data.pagination.total', 3);

        // Filters: specialty, availability, radius
        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders/shoppers?category_id='.$perfumes->id)
            ->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $near->id);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders/shoppers?available_only=1')
            ->assertOk()->assertJsonPath('data.pagination.total', 2);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders/shoppers?latitude='.self::LAT.'&longitude='.self::LNG.'&within_km=10')
            ->assertOk()->assertJsonPath('data.pagination.total', 2);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders/shoppers?search=Luxury')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | POST /custom-orders/{id}/assign-shopper
    |--------------------------------------------------------------------------
    */

    public function test_choosing_a_shopper_keeps_the_order_a_draft_until_confirmed(): void
    {
        $shopper = $this->shopper();
        $other   = $this->shopper();
        $order   = CustomOrder::factory()->create(['user_id' => $this->customer->id]);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['shopper_id' => $shopper->id])
            ->assertOk()
            ->assertJsonPath('message', __('custom_orders.assigned'))
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.assignment.mode', 'direct')
            ->assertJsonPath('data.assignment.assigned_at', null)
            ->assertJsonPath('data.shopper.name', $shopper->name)
            ->assertJsonPath('data.can_assign', true)
            ->assertJsonPath('data.can_confirm', true);

        $this->assertSame(0, AppNotification::query()->where('notifiable_id', $shopper->id)->count());

        // The choice can still be changed while it is a draft
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['shopper_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.shopper.name', $other->name);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['mode' => 'bidding'])
            ->assertOk()
            ->assertJsonPath('data.assignment.mode', 'bidding')
            ->assertJsonPath('data.assignment.is_open_for_bidding', false)   // opens on confirm
            ->assertJsonPath('data.shopper', null);
    }

    public function test_opens_bidding_on_confirm_and_notifies_available_shoppers(): void
    {
        $available = $this->shopper();
        $busy      = $this->shopper(['is_available' => false]);
        $order     = CustomOrder::factory()->create(['user_id' => $this->customer->id, 'delivery_city' => null, 'delivery_address' => null]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['mode' => 'bidding'])
            ->assertOk()
            ->assertJsonPath('message', __('custom_orders.bidding_opened'))
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.can_confirm', true);

        $this->assertSame(0, AppNotification::query()->where('notifiable_id', $available->id)->count());

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", [
                'address'     => ['city' => 'Jeddah', 'district' => 'Al Rawdah', 'street' => 'Tahlia', 'building_number' => '7', 'latitude' => 21.5, 'longitude' => 39.2],
                'delivery_at' => now()->addDays(2)->setTime(18, 30)->format('Y-m-d H:i'),
            ])
            ->assertOk()
            ->assertJsonPath('message', __('custom_orders.confirmed_bidding'))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.assignment.mode', 'bidding')
            ->assertJsonPath('data.assignment.is_open_for_bidding', true)
            ->assertJsonPath('data.shopper', null)
            ->assertJsonPath('data.can_assign', true)
            ->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.address.city', 'Jeddah')
            ->assertJsonPath('data.address.full_address', '7, Tahlia, Al Rawdah, Jeddah')
            ->assertJsonPath('data.delivery.slot', null)
            ->assertJsonPath('data.bids', []);

        $this->assertNotNull($order->fresh()->bidding_opened_at);
        $this->assertSame(1, AppNotification::query()->where('notifiable_id', $available->id)->count());
        $this->assertSame(0, AppNotification::query()->where('notifiable_id', $busy->id)->count());

        // A direct pick is still possible while bidding is open; it assigns right away and closes the round
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['mode' => 'direct', 'shopper_id' => $available->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.assignment.mode', 'direct')
            ->assertJsonPath('data.assignment.is_open_for_bidding', false)
            ->assertJsonPath('data.shopper.name', $available->name)
            ->assertJsonPath('data.can_assign', false);

        $this->assertNotNull($order->fresh()->assigned_at);
        $this->assertSame(2, AppNotification::query()->where('notifiable_id', $available->id)->count());
    }

    public function test_direct_mode_requires_a_shopper_and_rejects_non_shopper_accounts(): void
    {
        $order = CustomOrder::factory()->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['mode' => 'direct'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['shopper_id']]]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/assign-shopper", ['shopper_id' => $this->customer->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['shopper_id']]]);
    }

    /*
    |--------------------------------------------------------------------------
    | POST /custom-orders/{id}/confirm
    |--------------------------------------------------------------------------
    */

    public function test_confirms_a_draft_with_saved_address_and_delivery_slot_and_notifies_the_shopper(): void
    {
        $shopper = $this->shopper();
        $address = $this->address();
        $slot    = $this->slot('09:00:00', '11:00:00');
        $date    = now()->addDay()->toDateString();
        $order   = CustomOrder::factory()->create([
            'user_id' => $this->customer->id, 'shopper_id' => $shopper->id, 'assignment_mode' => 'direct',
            'delivery_city' => null, 'delivery_address' => null, 'delivery_at' => null,
        ]);
        CustomOrderItem::factory()->create(['custom_order_id' => $order->id]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", [
                'delivery_address_id' => $address->id,      // the column's name is accepted for address_id
                'delivery_date'      => $date,
                'delivery_slot_id'   => $slot->id,
                'confirmation_notes' => '  Call me when you arrive  ',
            ])
            ->assertOk();

        $response->assertJsonPath('message', __('custom_orders.confirmed'))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Waiting for shopper')
            ->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.can_assign', false)
            ->assertJsonPath('data.shopper.name', $shopper->name)
            ->assertJsonPath('data.address.id', $address->id)
            ->assertJsonPath('data.address.full_address', '12, King Fahd Rd, Olaya, Riyadh')
            ->assertJsonPath('data.address.latitude', self::LAT)
            ->assertJsonPath('data.delivery.date', $date)
            ->assertJsonPath('data.delivery.slot.id', $slot->id)
            ->assertJsonPath('data.delivery.slot.label', '9:00 AM - 11:00 AM')
            ->assertJsonPath('data.confirmation_notes', 'Call me when you arrive')
            ->assertJsonPath('data.pickup', null);   // set by the shopper with the invoice

        $this->assertNotNull($response->json('data.delivery.window_start'));
        $this->assertNotNull($response->json('data.delivery.window_end'));
        $this->assertNotNull($response->json('data.delivery_at'));
        $this->assertNotNull($response->json('data.timeline.submitted_at'));
        $this->assertNotNull($response->json('data.timeline.assigned_at'));

        $order->refresh();
        $this->assertSame($address->id, $order->delivery_address_id);
        $this->assertSame($address->id, $order->deliveryAddress->id);
        $this->assertNull($order->pickup_address_id);
        $this->assertSame($slot->id, $order->delivery_slot_id);
        $this->assertSame('09:00', $order->delivery_window_start->format('H:i'));
        $this->assertSame('11:00', $order->delivery_window_end->format('H:i'));

        // The shopper is told about the new request
        $this->assertSame(1, AppNotification::query()->where('notifiable_id', $shopper->id)->count());

        // Already confirmed -> 409
        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", ['address_id' => $address->id, 'delivery_date' => $date, 'delivery_slot_id' => $slot->id])
            ->assertStatus(409)
            ->assertJsonPath('message', __('custom_orders.not_confirmable', ['status' => 'Waiting for shopper']));
    }

    public function test_confirm_requires_a_shopper_choice_and_an_available_shopper(): void
    {
        $address = $this->address();
        $payload = ['address_id' => $address->id, 'delivery_at' => now()->addDay()->format('Y-m-d H:i')];

        // Step 2 skipped
        $order = CustomOrder::factory()->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', __('custom_orders.shopper_not_chosen'));

        $this->assertSame('draft', $order->fresh()->status);

        // The chosen shopper went busy in the meantime
        $shopper = $this->shopper();
        $order   = CustomOrder::factory()->create(['user_id' => $this->customer->id, 'shopper_id' => $shopper->id, 'assignment_mode' => 'direct']);
        $shopper->shopperProfile->update(['is_available' => false]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', __('custom_orders.shopper_unavailable'));

        $this->assertSame('draft', $order->fresh()->status);

        // Someone else's order
        $other = CustomOrder::factory()->create(['shopper_id' => $shopper->id, 'assignment_mode' => 'direct']);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$other->id}/confirm", $payload)
            ->assertNotFound();
    }

    public function test_confirm_validates_address_and_delivery_schedule(): void
    {
        $shopper = $this->shopper();
        $order   = CustomOrder::factory()->create(['user_id' => $this->customer->id, 'shopper_id' => $shopper->id, 'assignment_mode' => 'direct']);

        // Nothing sent: an address and a delivery time (or slot) are required
        $errors = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", [])
            ->assertUnprocessable()
            ->json('data.errors');

        $this->assertArrayHasKey('address_id', $errors);
        $this->assertArrayHasKey('address', $errors);
        $this->assertArrayHasKey('delivery_at', $errors);
        $this->assertArrayHasKey('delivery_slot_id', $errors);
        $this->assertSame(__('custom_orders.address_required'), $errors['address_id'][0]);
        $this->assertSame(__('custom_orders.delivery_required'), $errors['delivery_at'][0]);

        // Exact time too soon, slot without a date, unknown slot, inline address without a city
        $errors = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", [
                'address'          => ['district' => 'Olaya'],
                'delivery_at'      => now()->addMinutes(5)->format('Y-m-d H:i'),
                'delivery_slot_id' => 999,
            ])
            ->assertUnprocessable()
            ->json('data.errors');

        $this->assertArrayHasKey('address.city', $errors);
        $this->assertArrayHasKey('delivery_at', $errors);
        $this->assertArrayHasKey('delivery_date', $errors);
        $this->assertArrayHasKey('delivery_slot_id', $errors);

        // A slot that has already started today is not available
        $passed = $this->slot('00:00:00', '00:30:00');

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$order->id}/confirm", [
                'address_id'       => $this->address()->id,
                'delivery_date'    => now(config('checkout.delivery.timezone'))->toDateString(),
                'delivery_slot_id' => $passed->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('custom_orders.slot_unavailable'));

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(0, AppNotification::query()->where('notifiable_id', $shopper->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | GET /custom-orders, GET /custom-orders/{id}, cancel
    |--------------------------------------------------------------------------
    */

    public function test_lists_and_shows_only_the_customers_own_orders(): void
    {
        $shopper = $this->shopper();
        $mine    = CustomOrder::factory()->assignedTo($shopper)->create(['user_id' => $this->customer->id]);
        $draft   = CustomOrder::factory()->create(['user_id' => $this->customer->id]);
        $other   = CustomOrder::factory()->create();
        CustomOrderItem::factory()->count(2)->create(['custom_order_id' => $mine->id]);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.id', $draft->id)          // newest first
            ->assertJsonPath('data.items.1.id', $mine->id)
            ->assertJsonPath('data.items.1.items_count', 2)
            ->assertJsonPath('data.items.1.shopper.name', $shopper->name)
            ->assertJsonMissingPath('data.items.1.items');            // list is light

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/custom-orders?status=pending')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $mine->id);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/custom-orders/{$mine->id}", ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.status_label', 'بانتظار المتسوق')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.shopper.name', $shopper->name);

        // Only the four fields the order screen shows
        $card = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/custom-orders/{$mine->id}")->json('data.shopper');
        $this->assertSame(['name', 'photo', 'bio', 'rating'], array_keys($card));
        $this->assertSame(['average', 'count'], array_keys($card['rating']));

        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/custom-orders/{$other->id}")->assertNotFound();
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/custom-orders/abc')->assertNotFound();
    }

    public function test_customer_can_cancel_until_completed(): void
    {
        $pending   = CustomOrder::factory()->pending()->create(['user_id' => $this->customer->id]);
        $completed = CustomOrder::factory()->completed()->create(['user_id' => $this->customer->id]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$pending->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation.by', 'customer')
            ->assertJsonPath('data.cancellation.reason', 'Changed my mind')
            ->assertJsonPath('data.can_cancel', false);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/custom-orders/{$completed->id}/cancel")
            ->assertStatus(409);
    }

    public function test_requires_a_customer_account(): void
    {
        $this->getJson('/api/v1/custom-orders')->assertUnauthorized();

        $this->actingAs($this->shopper(), 'sanctum')
            ->getJson('/api/v1/custom-orders')
            ->assertForbidden();
    }
}
