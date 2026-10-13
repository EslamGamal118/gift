<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Filament\Resources\StoreResource\Pages\ViewStore;
use App\Filament\Resources\StoreResource\RelationManagers\AddonsRelationManager;
use App\Filament\Resources\StoreResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\StoreResource\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\StoreResource\RelationManagers\PendingDuesRelationManager;
use App\Filament\Resources\StoreResource\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\StoreResource\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\StoreResource\Widgets\StoreStatsOverview;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\Category;
use App\Models\Order;
use App\Models\StorePayout;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use App\Services\StorePayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class StoreResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendPushNotification::class]);

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        $this->customer = User::factory()->customer()->create();
    }

    private function store(array $attributes = []): StoreProfile
    {
        return StoreProfile::factory()->create($attributes + ['commission_rate' => 10]);
    }

    private function order(StoreProfile $store, float $subtotal, string $status = Order::STATUS_DELIVERED, string $payment = Order::PAYMENT_PAID): Order
    {
        return Order::create([
            'order_number' => 'GFT-'.uniqid(), 'user_id' => $this->customer->id, 'store_id' => $store->user_id,
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => $subtotal, 'delivery_fee' => 15,
            'tax_amount' => round($subtotal * 0.15, 2), 'total_amount' => round($subtotal * 1.15 + 15, 2),
            'status' => $status, 'payment_status' => $payment, 'paid_at' => now(),
            'delivered_at' => $status === Order::STATUS_DELIVERED ? now() : null,
        ]);
    }

    private function ownerNotifications(StoreProfile $store): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $store->user_id)->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    private function relation(string $manager, StoreProfile $store)
    {
        return Livewire::test($manager, ['ownerRecord' => $store, 'pageClass' => ViewStore::class]);
    }

    public function test_status_tabs_split_the_stores(): void
    {
        $pending = $this->store(['status' => StoreProfile::STATUS_PENDING]);
        $active = $this->store();
        $rejected = $this->store(['status' => StoreProfile::STATUS_REJECTED, 'rejection_reason' => 'Missing CR']);
        $suspended = $this->store();
        $suspended->user->update(['status' => 'blocked']);

        Livewire::test(ListStores::class)
            ->assertCanSeeTableRecords([$pending, $active, $rejected, $suspended])
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$active, $rejected, $suspended])
            ->set('activeTab', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$pending, $suspended])
            ->set('activeTab', 'rejected')
            ->assertCanSeeTableRecords([$rejected, $suspended])
            ->assertCanNotSeeTableRecords([$active, $pending])
            ->assertTableColumnStateSet('display_status', 'suspended', (string) $suspended->id);

        $this->assertSame('1', StoreResource::getNavigationBadge());
    }

    public function test_list_cells_sit_under_their_headers_in_both_directions(): void
    {
        $this->store(['phone' => '966512345678']);

        // Arabic (RTL): cells start on the right like the headers; actions follow their labelled header
        $html = $this->get(StoreResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringNotContainsString('dir="ltr"', $html);                 // no cell flipped to LTR
        $this->assertStringContainsString("\u{2066}966512345678\u{2069}", $html);  // phone isolated inline
        $this->assertStringContainsString(__('admin.common.actions'), $html);
        $this->assertMatchesRegularExpression('/fi-ta-actions[^"]*justify-start/', $html);
        $this->assertDoesNotMatchRegularExpression('/fi-ta-text[^>]*>\s*<div[^>]*text-(right|left)/', $html);

        // English (LTR): the same header-side placement
        $html = $this->get(StoreResource::getUrl('index').'?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('Actions', $html);
        $this->assertMatchesRegularExpression('/fi-ta-actions[^"]*justify-end/', $html);

        // Livewire updates (sort, filter, actions) must keep the chosen language
        $this->assertContains(\App\Http\Middleware\SetWebLocale::class, \Livewire\Livewire::getPersistentMiddleware());
    }

    public function test_list_counts_orders_and_products_and_keeps_review_actions_in_the_row_menu(): void
    {
        $busy = $this->store(['status' => StoreProfile::STATUS_PENDING]);
        $this->order($busy, 100);
        $this->order($busy, 50, Order::STATUS_CANCELLED);   // every order with the store counts
        foreach (['Rose box', 'Tulips', 'Card'] as $name) {
            $busy->user->products()->create(['category_id' => $busy->category_id, 'name' => $name, 'price' => 10, 'stock_quantity' => 1]);
        }
        $quiet = $this->store();

        $list = Livewire::test(ListStores::class)->sortTable('orders_count', 'desc');
        $rows = $list->instance()->getTableRecords()->keyBy('id');
        $this->assertSame([2, 3], [$rows[$busy->id]->orders_count, $rows[$busy->id]->products_count]);
        $this->assertSame([0, 0], [$rows[$quiet->id]->orders_count, $rows[$quiet->id]->products_count]);
        $list->assertCanSeeTableRecords([$busy, $quiet], inOrder: true)
            ->sortTable('products_count', 'asc')
            ->assertCanSeeTableRecords([$quiet, $busy], inOrder: true);

        // The row shows a single ⋮ menu; approve / reject live inside it and still work
        $actions = $list->instance()->getTable()->getActions();
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(\Filament\Tables\Actions\ActionGroup::class, reset($actions));
        $list->assertTableActionVisible('approve', $busy)
            ->assertTableActionVisible('reject', $busy)
            ->assertTableActionHidden('approve', $quiet)
            ->callTableAction('approve', $busy)
            ->assertHasNoTableActionErrors();
        $this->assertSame(StoreProfile::STATUS_APPROVED, $busy->fresh()->status);

        $html = $this->get(StoreResource::getUrl('index'))->getContent();
        $this->assertStringContainsString('عدد طلبات المتجر', $html);
        $this->assertStringContainsString('عدد المنتجات', $html);
    }

    public function test_city_filter_uses_the_store_branches(): void
    {
        $riyadh = $this->store();
        $riyadh->branches()->create(['name' => 'Main', 'city' => 'riyadh', 'is_main' => true]);
        $jeddah = $this->store();
        $jeddah->branches()->create(['name' => 'Main', 'city' => 'jeddah', 'is_main' => true]);

        Livewire::test(ListStores::class)
            ->filterTable('city', 'jeddah')
            ->assertCanSeeTableRecords([$jeddah])
            ->assertCanNotSeeTableRecords([$riyadh]);
    }

    public function test_approve_and_reject_from_the_list_notify_the_owner(): void
    {
        $toApprove = $this->store(['status' => StoreProfile::STATUS_PENDING]);
        $toReject = $this->store(['status' => StoreProfile::STATUS_PENDING]);

        Livewire::test(ListStores::class)
            ->callTableAction('approve', $toApprove)
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $toReject, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required'])
            ->callTableAction('reject', $toReject, data: ['reason' => 'Commercial register expired'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StoreProfile::STATUS_APPROVED, $toApprove->fresh()->status);
        $this->assertSame(StoreProfile::STATUS_REJECTED, $toReject->fresh()->status);
        $this->assertSame('Commercial register expired', $toReject->fresh()->rejection_reason);
        $this->assertSame(['notifications.store_approved_title'], $this->ownerNotifications($toApprove));
        $this->assertSame(['notifications.store_rejected_title'], $this->ownerNotifications($toReject));
    }

    public function test_suspending_blocks_the_owner_and_hides_the_store_until_reactivated(): void
    {
        $store = $this->store();

        Livewire::test(ViewStore::class, ['record' => $store->getRouteKey()])
            ->callAction('suspend', data: ['reason' => 'Customer complaints'])
            ->assertHasNoActionErrors();

        $store->refresh();
        $this->assertSame('blocked', $store->user->status);
        $this->assertSame('Customer complaints', $store->suspension_reason);
        $this->assertFalse(StoreProfile::visible()->whereKey($store->id)->exists());

        Livewire::test(ViewStore::class, ['record' => $store->getRouteKey()])
            ->callAction('reactivate')
            ->assertHasNoActionErrors();

        $store->refresh();
        $this->assertSame('active', $store->user->status);
        $this->assertNull($store->suspended_at);
        $this->assertTrue(StoreProfile::visible()->whereKey($store->id)->exists());
        $this->assertSame(['notifications.store_suspended_title', 'notifications.store_reactivated_title'], $this->ownerNotifications($store));
    }

    public function test_financials_follow_the_commission_and_payouts(): void
    {
        $store = $this->store();
        $this->order($store, 100);
        $this->order($store, 200);
        $this->order($store, 50, Order::STATUS_PROCESSING);                                // earning, not payable yet
        $this->order($store, 70, Order::STATUS_CANCELLED);                                 // excluded
        $this->order($store, 90, Order::STATUS_PENDING_PAYMENT, Order::PAYMENT_PENDING);   // excluded
        $this->order($store, 40, Order::STATUS_DELIVERED, Order::PAYMENT_REFUNDED);        // excluded

        $this->assertSame([
            'total_sales' => 350.0, 'commission' => 35.0, 'store_earnings' => 315.0,
            'available' => 270.0, 'due_orders_count' => 2, 'transferred' => 0.0,
        ], $store->financials());

        $this->relation(PendingDuesRelationManager::class, $store)
            ->assertCanSeeTableRecords(Order::where('status', Order::STATUS_DELIVERED)->where('payment_status', 'paid')->get())
            ->callTableAction('payoutAll', data: ['method' => StorePayout::METHOD_BANK_TRANSFER, 'reference' => 'TRX-1001', 'transferred_at' => now()])
            ->assertHasNoTableActionErrors()
            ->assertDispatched('finances-updated')
            ->assertCountTableRecords(0);

        $payout = StorePayout::sole();
        $this->assertSame('270.00', $payout->amount);
        $this->assertSame(2, $payout->orders_count);
        $this->assertSame($this->admin->id, $payout->created_by);
        $this->assertSame(['notifications.store_payout_title'], $this->ownerNotifications($store));

        // A later commission change never rewrites what was already paid out
        $store->update(['commission_rate' => 20]);
        $this->assertSame([
            'total_sales' => 350.0, 'commission' => 40.0, 'store_earnings' => 310.0,
            'available' => 0.0, 'due_orders_count' => 0, 'transferred' => 270.0,
        ], $store->fresh()->financials());

        Livewire::test(StoreStatsOverview::class, ['record' => $store])
            ->assertSee(__('admin.stores.stats.transferred'))
            ->assertSee(Number::currency(270, 'SAR', app()->getLocale()));
    }

    public function test_selected_orders_can_be_paid_out_and_never_twice(): void
    {
        $store = $this->store();
        $first = $this->order($store, 100);
        $second = $this->order($store, 300);

        $this->relation(PendingDuesRelationManager::class, $store)
            ->callTableBulkAction('payoutSelected', [$first], data: ['method' => StorePayout::METHOD_WALLET, 'transferred_at' => now()])
            ->assertHasNoTableBulkActionErrors()
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);

        $this->assertSame('90.00', StorePayout::sole()->amount);

        $this->expectException(RuntimeException::class);
        app(StorePayoutService::class)->payout($store, [$first->id], ['method' => 'cash']);
    }

    public function test_view_page_and_every_tab_render(): void
    {
        $store = $this->store();
        $order = $this->order($store, 120);
        $order->items()->create(['product_name' => 'Red roses', 'unit_price' => 60, 'quantity' => 2, 'subtotal' => 120]);
        $store->user->products()->create(['category_id' => $store->category_id, 'name' => 'Rose box', 'price' => 60, 'stock_quantity' => 3]);
        $store->user->addons()->create(['name' => 'Gift wrap', 'price' => 10, 'stock_quantity' => 0]);

        $this->get(StoreResource::getUrl('view', ['record' => $store]))->assertOk()->assertSee($store->store_name);
        $this->get(StoreResource::getUrl('edit', ['record' => $store]))->assertOk();
        $this->get(StoreResource::getUrl('create'))->assertOk();

        foreach ([ProductsRelationManager::class, AddonsRelationManager::class, OrdersRelationManager::class, PendingDuesRelationManager::class, PayoutsRelationManager::class, ReviewsRelationManager::class] as $manager) {
            $this->relation($manager, $store)->assertSuccessful();
        }

        $this->relation(ProductsRelationManager::class, $store)->assertTableColumnStateSet('stock_state', 'low', (string) $store->user->products()->first()->id);
        $this->relation(OrdersRelationManager::class, $store)
            ->assertTableColumnStateSet('store_share', 108.0, (string) $order->id)
            ->mountTableAction('view', $order)
            ->assertSee('Red roses');
    }

    public function test_hiding_a_review_recalculates_the_store_rating(): void
    {
        $store = $this->store(['rating_avg' => 0, 'rating_count' => 0]);
        $good = StoreReview::create(['store_profile_id' => $store->id, 'user_id' => $this->customer->id, 'rating' => 5, 'comment' => 'Great']);
        $bad = StoreReview::create(['store_profile_id' => $store->id, 'user_id' => $this->customer->id, 'rating' => 1, 'comment' => 'Late']);
        $this->assertSame(2, $store->fresh()->rating_count);

        $this->relation(ReviewsRelationManager::class, $store)
            ->assertCanSeeTableRecords([$good, $bad])
            ->call('updateTableColumnState', 'is_visible', (string) $bad->id, false);

        $this->assertFalse($bad->fresh()->is_visible);
        $this->assertSame(1, $store->fresh()->rating_count);
        $this->assertEquals(5, $store->fresh()->rating_avg);
    }

    public function test_create_makes_the_owner_account_with_the_store(): void
    {
        $category = Category::factory()->create();

        Livewire::test(CreateStore::class)
            ->fillForm([
                'owner_name' => 'Huda Ali',
                'owner_phone' => '0503333333',
                'store_name' => 'Huda Flowers',
                'category_id' => $category->id,
                'iban' => 'sa03 8000 0000 6080 1016 7519',
                'iban_certificate_file' => null,
                'commission_rate' => 12.5,
                'status' => StoreProfile::STATUS_APPROVED,
                'working_hours.saturday.is_open' => true,
                'working_hours.saturday.from' => '09:00',
                'working_hours.saturday.to' => '22:00',
                'branches' => [['name' => 'Olaya', 'city' => 'riyadh', 'is_main' => true, 'is_active' => true]],
            ])
            ->call('create')
            ->assertHasFormErrors(['iban_certificate_file' => 'required']);

        Storage::fake('public');

        Livewire::test(CreateStore::class)
            ->fillForm([
                'owner_name' => 'Huda Ali',
                'owner_phone' => '0503333333',
                'store_name' => 'Huda Flowers',
                'category_id' => $category->id,
                'iban' => 'sa03 8000 0000 6080 1016 7519',
                'iban_certificate_file' => UploadedFile::fake()->create('iban.pdf', 40, 'application/pdf'),
                'commission_rate' => 12.5,
                'status' => StoreProfile::STATUS_APPROVED,
                'working_hours.saturday.is_open' => true,
                'working_hours.saturday.from' => '09:00',
                'working_hours.saturday.to' => '22:00',
                'branches' => [['name' => 'Olaya', 'city' => 'riyadh', 'is_main' => true, 'is_active' => true]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $store = StoreProfile::where('store_name', 'Huda Flowers')->firstOrFail();
        $this->assertSame(User::TYPE_STORE, $store->user->user_type);
        $this->assertSame('966503333333', $store->user->phone);
        $this->assertSame('SA0380000000608010167519', $store->iban);
        $this->assertSame('12.50', $store->commission_rate);
        $this->assertEquals(['is_open' => true, 'from' => '09:00', 'to' => '22:00'], $store->working_hours['saturday']);
        $this->assertEquals(['is_open' => false, 'from' => null, 'to' => null], $store->working_hours['friday']);
        $this->assertSame('riyadh', $store->branches()->sole()->city);
        $this->assertNotNull($store->submitted_at);
        Storage::disk('public')->assertExists($store->iban_certificate_file);
    }

    public function test_status_change_in_the_form_notifies_and_delete_is_soft(): void
    {
        $store = $this->store(['status' => StoreProfile::STATUS_PENDING]);

        Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])
            ->fillForm(['status' => StoreProfile::STATUS_REJECTED, 'rejection_reason' => 'Unclear photos'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Unclear photos', $store->fresh()->rejection_reason);
        $this->assertSame(['notifications.store_rejected_title'], $this->ownerNotifications($store));

        Livewire::test(ListStores::class)->callTableAction('delete', $store);

        $this->assertSoftDeleted($store);
        $this->assertNull($store->user->fresh()->storeProfile);

        Livewire::test(ListStores::class)
            ->filterTable('trashed', true)
            ->callTableAction('restore', (string) $store->id);

        $this->assertNotSoftDeleted($store);
    }
}
