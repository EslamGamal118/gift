<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\CustomerResource\RelationManagers\ReviewsRelationManager as CustomerReviewsRelationManager;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\ShopperResource;
use App\Filament\Resources\ShopperResource\Pages\CreateShopper;
use App\Filament\Resources\ShopperResource\Pages\EditShopper;
use App\Filament\Resources\ShopperResource\Pages\ListShoppers;
use App\Filament\Resources\ShopperResource\Pages\ViewShopper;
use App\Filament\Resources\ShopperResource\RelationManagers\CustomOrdersRelationManager;
use App\Filament\Resources\ShopperResource\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\ShopperResource\RelationManagers\PendingDuesRelationManager;
use App\Filament\Resources\ShopperResource\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\ShopperResource\Widgets\ShopperStatsOverview;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\Category;
use App\Models\CustomOrder;
use App\Models\ShopperPayout;
use App\Models\ShopperProfile;
use App\Models\User;
use App\Services\ReviewFeedService;
use App\Services\ShopperPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ShopperResourceTest extends TestCase
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

    private function shopper(array $attributes = []): ShopperProfile
    {
        return ShopperProfile::factory()->create($attributes + ['commission_rate' => 10]);
    }

    private function order(ShopperProfile $shopper, ?float $finalAmount, ?float $fee, string $status = CustomOrder::STATUS_COMPLETED, string $payment = CustomOrder::PAYMENT_PAID): CustomOrder
    {
        return CustomOrder::factory()->create([
            'user_id' => $this->customer->id,
            'shopper_id' => $shopper->user_id,
            'status' => $status,
            'payment_status' => $payment,
            'final_amount' => $finalAmount,
            'shopper_fees' => $fee,
            'total_amount' => $finalAmount,
            'paid_at' => $payment === CustomOrder::PAYMENT_PAID ? now() : null,
            'completed_at' => $status === CustomOrder::STATUS_COMPLETED ? now() : null,
        ]);
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    private function notificationsOf(ShopperProfile $shopper): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $shopper->user_id)->orderBy('id')->get()
            ->map(fn ($n) => [
                'title' => $n->content->title_key,
                'body' => __($n->content->body_key, $n->content->body_params ?? []),
            ])->all();
    }

    private function relation(string $manager, ShopperProfile $shopper)
    {
        return Livewire::test($manager, ['ownerRecord' => $shopper, 'pageClass' => ViewShopper::class]);
    }

    public function test_status_tabs_split_the_shoppers(): void
    {
        $pending = $this->shopper(['status' => ShopperProfile::STATUS_PENDING]);
        $active = $this->shopper();
        $rejected = $this->shopper(['status' => ShopperProfile::STATUS_REJECTED, 'rejection_reason' => 'Blurry ID']);
        $suspended = $this->shopper();
        $suspended->user->update(['status' => 'blocked']);

        Livewire::test(ListShoppers::class)
            ->assertCanSeeTableRecords([$pending, $active, $rejected, $suspended])
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$active, $rejected, $suspended])
            ->set('activeTab', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$pending, $suspended])
            ->set('activeTab', 'rejected')
            ->assertCanSeeTableRecords([$rejected, $suspended])
            ->assertCanNotSeeTableRecords([$active, $pending]);

        $this->assertSame('1', ShopperResource::getNavigationBadge());
    }

    public function test_approve_and_reject_send_the_exact_messages(): void
    {
        $toApprove = $this->shopper(['status' => ShopperProfile::STATUS_PENDING]);
        $toReject = $this->shopper(['status' => ShopperProfile::STATUS_PENDING]);

        Livewire::test(ListShoppers::class)
            ->callTableAction('approve', $toApprove)
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $toReject, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required'])
            ->callTableAction('reject', $toReject, data: ['reason' => 'صورة الهوية غير واضحة'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ShopperProfile::STATUS_APPROVED, $toApprove->fresh()->status);
        $this->assertSame('صورة الهوية غير واضحة', $toReject->fresh()->rejection_reason);

        app()->setLocale('ar');
        $this->assertSame([['title' => 'notifications.shopper_approved_title', 'body' => 'تم قبول حسابك كمتسوق شخصي، يمكنك الآن استقبال الطلبات الخاصة.']], $this->notificationsOf($toApprove));
        $this->assertSame([['title' => 'notifications.shopper_rejected_title', 'body' => 'عفواً، تم رفض طلب انضمامك كمتسوق شخصي. السبب: صورة الهوية غير واضحة']], $this->notificationsOf($toReject));
        Queue::assertPushed(SendPushNotification::class, 2);   // FCM push for each decision
    }

    public function test_suspension_and_status_changes_notify_the_shopper(): void
    {
        $shopper = $this->shopper();

        Livewire::test(ViewShopper::class, ['record' => $shopper->getRouteKey()])
            ->callAction('suspend', data: ['reason' => 'شكاوى متكررة'])
            ->assertHasNoActionErrors();

        $this->assertSame('blocked', $shopper->user->fresh()->status);
        $this->assertFalse(ShopperProfile::visible()->whereKey($shopper->id)->exists());

        Livewire::test(ViewShopper::class, ['record' => $shopper->getRouteKey()])
            ->callAction('reactivate')
            ->assertHasNoActionErrors();

        $this->assertTrue(ShopperProfile::visible()->whereKey($shopper->id)->exists());

        // A status changed from the edit form notifies too
        Livewire::test(EditShopper::class, ['record' => $shopper->getRouteKey()])
            ->fillForm(['status' => ShopperProfile::STATUS_REJECTED, 'rejection_reason' => 'Expired license'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['notifications.shopper_suspended_title', 'notifications.shopper_reactivated_title', 'notifications.shopper_rejected_title'],
            array_column($this->notificationsOf($shopper), 'title'),
        );
    }

    public function test_financials_reimburse_items_and_take_commission_on_the_fee_only(): void
    {
        $shopper = $this->shopper();
        $first = $this->order($shopper, 125, 25);                                                     // items 100 + fee 25
        $second = $this->order($shopper, 230, 30);                                                    // items 200 + fee 30
        $this->order($shopper, 60, 10, CustomOrder::STATUS_IN_PROGRESS);                              // earning, not payable yet
        $this->order($shopper, 500, 50, CustomOrder::STATUS_COMPLETED, CustomOrder::PAYMENT_PENDING); // unpaid: excluded
        $this->order($shopper, 80, 20, CustomOrder::STATUS_CANCELLED);                                // cancelled: excluded

        $this->assertEquals([
            'assigned_orders' => 5, 'completed_orders' => 3,
            'net_earnings' => 58.5, 'commission' => 6.5,
            'available' => 349.5, 'due_orders_count' => 2, 'transferred' => 0.0,
        ], $shopper->financials());

        $this->relation(PendingDuesRelationManager::class, $shopper)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertTableColumnStateSet('shopper_amount', 122.5, (string) $first->id)
            ->callTableAction('payoutAll', data: ['method' => 'bank_transfer', 'reference' => 'TRX-77', 'transferred_at' => now()])
            ->assertHasNoTableActionErrors()
            ->assertDispatched('finances-updated')
            ->assertCountTableRecords(0);

        $payout = ShopperPayout::sole();
        $this->assertSame('349.50', $payout->amount);
        $this->assertSame(2, $payout->orders_count);
        $this->assertEquals(
            ['items_cost' => '100.00', 'shopper_fee' => '25.00', 'commission_amount' => '2.50', 'shopper_amount' => '122.50'],
            $payout->items()->where('custom_order_id', $first->id)->first()->only(['items_cost', 'shopper_fee', 'commission_amount', 'shopper_amount']),
        );
        $this->assertContains('notifications.shopper_payout_title', array_column($this->notificationsOf($shopper), 'title'));

        // A later commission change never rewrites what was already paid out
        $shopper->update(['commission_rate' => 20]);
        $money = $shopper->fresh()->financials();
        $this->assertEquals(57.5, $money['net_earnings']);   // 55 - 5.5 frozen + 10 * 0.8
        $this->assertEquals(349.5, $money['transferred']);

        Livewire::test(ShopperStatsOverview::class, ['record' => $shopper])->assertSee(__('admin.shoppers.stats.earnings'));
    }

    public function test_selected_orders_can_be_paid_out_and_never_twice(): void
    {
        $shopper = $this->shopper();
        $first = $this->order($shopper, 125, 25);
        $second = $this->order($shopper, 230, 30);

        $this->relation(PendingDuesRelationManager::class, $shopper)
            ->callTableBulkAction('payoutSelected', [$first], data: ['method' => 'wallet', 'transferred_at' => now()])
            ->assertHasNoTableBulkActionErrors()
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);

        $this->assertSame('122.50', ShopperPayout::sole()->amount);

        $this->expectException(RuntimeException::class);
        app(ShopperPayoutService::class)->payout($shopper, [$first->id], ['method' => 'cash']);
    }

    public function test_view_page_and_every_tab_render(): void
    {
        $shopper = $this->shopper();
        $order = $this->order($shopper, 125, 25);
        $order->items()->create(['product_name' => 'Perfume', 'quantity' => 1, 'unit_price' => 100]);
        DB::table('custom_order_bids')->insert([
            'custom_order_id' => $order->id, 'shopper_id' => $shopper->user_id, 'amount' => 140, 'service_fee' => 25,
            'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(ShopperResource::getUrl('view', ['record' => $shopper]))->assertOk()->assertSee($shopper->user->name);
        $this->get(ShopperResource::getUrl('edit', ['record' => $shopper]))->assertOk();
        $this->get(ShopperResource::getUrl('create'))->assertOk();

        foreach ([CustomOrdersRelationManager::class, PendingDuesRelationManager::class, PayoutsRelationManager::class, ReviewsRelationManager::class] as $manager) {
            $this->relation($manager, $shopper)->assertSuccessful();
        }

        $this->relation(CustomOrdersRelationManager::class, $shopper)
            ->assertCanSeeTableRecords([$order])
            ->assertSee('Perfume')
            ->mountTableAction('view', $order)
            ->assertSee('Perfume');

        $order->load('payoutItem');
        $order->accepted_bid_amount = 140;
        $this->assertSame(140.0, CustomOrdersRelationManager::quotedPrice($order));
        $this->assertSame(2.5, CustomOrdersRelationManager::platformFee($order, $shopper));
    }

    public function test_hiding_a_review_updates_the_rating_and_the_app_feed(): void
    {
        $shopper = $this->shopper(['rating_avg' => 0, 'rating_count' => 0]);
        $good = $this->order($shopper, 125, 25)->review()->create(['user_id' => $this->customer->id, 'store_rating' => 5, 'products_rating' => 5]);
        $bad = $this->order($shopper, 125, 25)->review()->create(['user_id' => $this->customer->id, 'store_rating' => 1, 'products_rating' => 2, 'store_comment' => 'Late']);
        app(\App\Services\ReviewService::class)->recalculateShopper($shopper->user_id);
        $this->assertSame(2, $shopper->fresh()->rating_count);

        $this->relation(ReviewsRelationManager::class, $shopper)
            ->assertCanSeeTableRecords([$good, $bad])
            ->call('updateTableColumnState', 'is_visible', (string) $bad->id, false);

        $this->assertFalse($bad->fresh()->is_visible);
        $this->assertSame(1, $shopper->fresh()->rating_count);
        $this->assertEquals(5, $shopper->fresh()->rating_avg);
        $feed = app(ReviewFeedService::class)->forShopper($shopper->user, [], 10)['reviews'];
        $this->assertSame([$good->id], collect($feed->items())->pluck('id')->all());

        // The same switch from the customer's page
        Livewire::test(CustomerReviewsRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
            ->assertTableColumnStateSet('store_review_visible', 'hidden', $bad)
            ->callTableAction('toggleVisibility', $bad);

        $this->assertTrue($bad->fresh()->is_visible);
        $this->assertSame(2, $shopper->fresh()->rating_count);
    }

    public function test_create_makes_the_shopper_account_with_coverage(): void
    {
        $categories = Category::factory()->count(2)->create();

        Livewire::test(CreateShopper::class)
            ->fillForm([
                'owner_name' => 'Noura Saad',
                'owner_phone' => '0504444444',
                'national_id_number' => '1098765432',
                'city' => 'jeddah',
                'categories' => $categories->modelKeys(),
                'iban' => 'sa03 8000 0000 6080 1016 7519',
                'commission_rate' => 15,
                'status' => ShopperProfile::STATUS_APPROVED,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $shopper = ShopperProfile::whereHas('user', fn ($q) => $q->where('name', 'Noura Saad'))->firstOrFail();
        $this->assertSame(User::TYPE_SHOPPER, $shopper->user->user_type);
        $this->assertSame('966504444444', $shopper->user->phone);
        $this->assertSame('jeddah', $shopper->city);
        $this->assertSame('SA0380000000608010167519', $shopper->iban);
        $this->assertSame('15.00', $shopper->commission_rate);
        $this->assertEqualsCanonicalizing($categories->modelKeys(), $shopper->categories()->pluck('categories.id')->all());

        Livewire::test(ListShoppers::class)
            ->filterTable('city', 'jeddah')
            ->assertCanSeeTableRecords([$shopper]);
    }

    public function test_list_cells_sit_under_their_headers(): void
    {
        $this->shopper()->user->update(['phone' => '966512345678']);

        $html = $this->get(ShopperResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('dir="ltr"', $html);
        $this->assertStringContainsString("\u{2066}966512345678\u{2069}", $html);
        $this->assertMatchesRegularExpression('/fi-ta-actions-cell.*?fi-ta-actions [^"]*justify-start/s', $html);
    }
}
