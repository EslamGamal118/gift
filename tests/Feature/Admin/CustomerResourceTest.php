<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\CustomOrdersRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\CustomerResource\Widgets\CustomerStatsOverview;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private StoreProfile $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());

        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed', 'phone' => '966501111111']);
        $this->store = StoreProfile::factory()->create();
    }

    private function order(User $customer, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'order_number' => 'GFT-'.uniqid(), 'user_id' => $customer->id, 'store_id' => $this->store->user_id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'tax_amount' => 15, 'total_amount' => 115,
            'status' => Order::STATUS_DELIVERED, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ]);
    }

    public function test_list_shows_only_customers_with_their_totals(): void
    {
        $this->order($this->customer);
        $this->order($this->customer, ['payment_status' => Order::PAYMENT_REFUNDED]);   // not counted as spent
        CustomOrder::factory()->create(['user_id' => $this->customer->id, 'payment_status' => 'paid', 'total_amount' => 85]);
        $other = User::factory()->customer()->create();

        Livewire::test(ListCustomers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->customer, $other])
            ->assertCanNotSeeTableRecords([User::find($this->store->user_id)])
            ->assertTableColumnStateSet('orders_total_count', 3, (string) $this->customer->id)
            ->assertTableColumnStateSet('total_spent', 200.0, (string) $this->customer->id)
            ->sortTable('total_spent', 'desc')
            ->assertCanSeeTableRecords([$this->customer, $other], inOrder: true)
            ->sortTable('orders_total_count')
            ->assertCanSeeTableRecords([$other, $this->customer], inOrder: true)
            ->searchTable('966501111111')
            ->assertCanSeeTableRecords([$this->customer])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_view_page_renders_with_stats_and_every_tab(): void
    {
        $order = $this->order($this->customer);
        $order->items()->create(['product_name' => 'Red roses', 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100]);
        $order->review()->create(['user_id' => $this->customer->id, 'store_rating' => 4, 'products_rating' => 5, 'store_comment' => 'Lovely']);

        $this->get(CustomerResource::getUrl('view', ['record' => $this->customer]))
            ->assertOk()
            ->assertSee('Sara Ahmed');

        // Header widgets load lazily: render the stats on their own
        Livewire::test(CustomerStatsOverview::class, ['record' => $this->customer])
            ->assertSee('4.0 ★')
            ->assertSee(__('admin.customers.stats.orders'));

        foreach ([OrdersRelationManager::class, CustomOrdersRelationManager::class, PaymentsRelationManager::class, ReviewsRelationManager::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])->assertSuccessful();
        }

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$order])
            ->mountTableAction('view', $order)
            ->assertSee('Red roses');
    }

    public function test_payments_tab_lists_the_customers_transactions_only(): void
    {
        $order = $this->order($this->customer);
        $custom = CustomOrder::factory()->create(['user_id' => $this->customer->id]);
        $mine = [
            $order->transactions()->create(['gateway' => 'tabby', 'event' => 'capture', 'status' => 'captured', 'amount' => 115]),
            $custom->transactions()->create(['gateway' => 'alrajhi', 'event' => 'callback', 'status' => 'failed', 'amount' => 50]),
        ];
        $theirs = $this->order(User::factory()->customer()->create())
            ->transactions()->create(['gateway' => 'tamara', 'event' => 'capture', 'status' => 'captured', 'amount' => 99]);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords([$theirs])
            ->mountTableAction('view', $mine[0])
            ->assertSee($order->order_number);

        $this->assertSame(2, PaymentTransaction::forCustomer($this->customer->id)->count());
    }

    public function test_admin_can_hide_a_store_review(): void
    {
        $order = $this->order($this->customer);
        $review = $order->review()->create(['user_id' => $this->customer->id, 'store_rating' => 2, 'products_rating' => 3]);
        $storeReview = StoreReview::create(['store_profile_id' => $this->store->id, 'user_id' => $this->customer->id, 'order_id' => $order->id, 'rating' => 2]);

        Livewire::test(ReviewsRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$review])
            ->assertTableColumnStateSet('store_review_visible', 'visible', $review)
            ->callTableAction('toggleVisibility', $review);

        $this->assertFalse($storeReview->fresh()->is_visible);
    }

    public function test_create_normalizes_the_phone_and_rejects_duplicates(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'New Customer', 'phone' => '0502222222', 'status' => 'active'])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('phone', '966502222222')->first();
        $this->assertSame(User::TYPE_CUSTOMER, $created?->user_type);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Dup', 'phone' => '0501111111', 'status' => 'active'])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Bad', 'phone' => '12345', 'status' => 'active'])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }

    public function test_edit_keeps_the_password_when_left_blank_and_blocks_deleting_customers_with_orders(): void
    {
        $this->customer->update(['password' => 'secret-123']);

        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])
            ->fillForm(['name' => 'Sara A.', 'status' => 'blocked', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->customer->refresh();
        $this->assertSame('Sara A.', $this->customer->name);
        $this->assertSame('blocked', $this->customer->status);
        $this->assertTrue(Hash::check('secret-123', $this->customer->password));

        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])->assertActionVisible(DeleteAction::class);

        $this->order($this->customer);

        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])->assertActionHidden(DeleteAction::class);
    }

    public function test_non_customer_records_are_not_reachable(): void
    {
        $this->get(CustomerResource::getUrl('view', ['record' => $this->store->user_id]))->assertNotFound();
    }
}
