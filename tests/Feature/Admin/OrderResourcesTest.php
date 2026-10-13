<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ShopperOrderResource;
use App\Filament\Resources\ShopperOrderResource\Pages\ListShopperOrders;
use App\Filament\Resources\ShopperOrderResource\Pages\ViewShopperOrder;
use App\Filament\Resources\StoreOrderResource;
use App\Filament\Resources\StoreOrderResource\Pages\ListStoreOrders;
use App\Filament\Resources\StoreOrderResource\Pages\ViewStoreOrder;
use App\Jobs\SendPushNotification;
use App\Models\Addon;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OrderResourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private StoreProfile $store;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendPushNotification::class]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin One']);
        $this->actingAs($this->admin);
        $this->customer = User::factory()->customer()->create(['name' => 'Sara', 'locale' => 'ar']);
        $this->store = StoreProfile::factory()->create(['store_name' => 'Rose House']);
    }

    private function order(string $status = Order::STATUS_PENDING, string $payment = Order::PAYMENT_PAID, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'order_number' => 'GFT-'.uniqid(), 'user_id' => $this->customer->id, 'store_id' => $this->store->user_id,
            'shipping_name' => 'Nora', 'shipping_phone' => '+966500000001',
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'shipping_latitude' => 24.7136, 'shipping_longitude' => 46.6753,
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 200, 'delivery_fee' => 15,
            'tax_rate' => 0.15, 'tax_amount' => 32.25, 'total_amount' => 247.25,
            'status' => $status, 'payment_status' => $payment, 'paid_at' => now(),
        ]);
    }

    private function customOrder(?ShopperProfile $shopper, string $status, string $payment = CustomOrder::PAYMENT_PENDING, array $attributes = []): CustomOrder
    {
        return CustomOrder::factory()->create($attributes + [
            'user_id' => $this->customer->id,
            'shopper_id' => $shopper?->user_id,
            'status' => $status,
            'payment_status' => $payment,
            'final_amount' => 350,     // items 300 + fee 50
            'shopper_fees' => 50,
            'delivery_fee' => 20,
            'tax_amount' => 55.5,
            'total_amount' => 425.5,
            'paid_at' => $payment === CustomOrder::PAYMENT_PAID ? now() : null,
        ]);
    }

    /**
     * @return list<string>  title keys of the user's notifications, oldest first
     */
    private function notificationsOf(User $user): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $user->id)->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_both_resources_share_the_arabic_orders_group(): void
    {
        app()->setLocale('ar');

        $this->assertSame('إدارة الطلبات', StoreOrderResource::getNavigationGroup());
        $this->assertSame(StoreOrderResource::getNavigationGroup(), ShopperOrderResource::getNavigationGroup());
        $this->assertSame(['طلبات المتاجر', 'طلبات المتسوق الشخصي'], [StoreOrderResource::getNavigationLabel(), ShopperOrderResource::getNavigationLabel()]);

        $this->order();
        $this->customOrder(ShopperProfile::factory()->create(), CustomOrder::STATUS_PENDING);

        foreach (['store-orders', 'shopper-orders'] as $slug) {
            $html = $this->get("/admin/{$slug}")->assertOk()->getContent();
            $this->assertStringContainsString('dir="rtl"', $html);
            $this->assertStringContainsString('إدارة الطلبات', $html);
            $this->assertStringNotContainsString('dir="ltr"', $html);
            $this->assertMatchesRegularExpression('/fi-ta-actions-cell.*?fi-ta-actions [^"]*justify-start/s', $html);
        }
    }

    public function test_store_orders_list_filters_by_status_group_payment_and_date(): void
    {
        $new = $this->order();
        $onTheWay = $this->order(Order::STATUS_ORDER_PICKED_UP);   // with the delivery company
        $unpaid = $this->order(Order::STATUS_PENDING_PAYMENT, Order::PAYMENT_PENDING);
        $old = $this->order(Order::STATUS_DELIVERED);
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();

        Livewire::test(ListStoreOrders::class)
            ->assertCanSeeTableRecords([$new, $onTheWay, $unpaid, $old])
            ->assertTableColumnStateSet('storeProfile.store_name', 'Rose House', $new->getKey())
            ->filterTable('status_group', ['out_for_delivery'])
            ->assertCanSeeTableRecords([$onTheWay])->assertCanNotSeeTableRecords([$new, $unpaid, $old])
            ->resetTableFilters()
            ->filterTable('payment_status', [Order::PAYMENT_PENDING])
            ->assertCanSeeTableRecords([$unpaid])->assertCanNotSeeTableRecords([$new])
            ->resetTableFilters()
            ->filterTable('created_between', ['from' => now()->subDays(7)->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()
            ->searchTable('Rose')
            ->assertCanSeeTableRecords([$new]);
    }

    public function test_admin_moves_a_store_order_forward_and_the_customer_is_notified(): void
    {
        $order = $this->order();

        Livewire::test(ListStoreOrders::class)
            ->assertTableActionVisible('updateStatus', $order->getKey())
            ->callTableAction('updateStatus', $order->getKey(), ['status' => Order::STATUS_ACCEPTED])
            ->assertHasNoTableActionErrors();

        $order->refresh();
        $this->assertSame(Order::STATUS_ACCEPTED, $order->status);
        $this->assertNotNull($order->accepted_at);
        $history = $order->statusHistories()->sole();
        $this->assertSame([Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::ACTOR_SYSTEM, $this->admin->id], [$history->from_status, $history->to_status, $history->actor_type, $history->actor_id]);

        // Steps may be skipped, never gone back
        Livewire::test(ViewStoreOrder::class, ['record' => $order->getKey()])
            ->callAction('updateStatus', ['status' => Order::STATUS_OUT_FOR_DELIVERY])
            ->assertHasNoActionErrors()
            ->assertSee('الإدارة (Admin One)');

        $this->assertSame(Order::STATUS_OUT_FOR_DELIVERY, $order->fresh()->status);
        $this->assertSame([Order::STATUS_DELIVERED, Order::STATUS_CANCELLED], app(\App\Services\AdminOrderStatusService::class)->storeTargets($order->fresh()));

        $this->assertSame(['notifications.order_accepted_title', 'notifications.order_out_for_delivery_title'], $this->notificationsOf($this->customer));
        $content = AppNotification::query()->where('notifiable_id', $this->customer->id)->latest('id')->first()->content;
        $this->assertSame('طلبك في الطريق إليك', __($content->title_key, [], 'ar'));
        Queue::assertPushed(SendPushNotification::class, 2);
    }

    public function test_cancelling_a_store_order_needs_a_reason_and_releases_stock(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->user_id, 'stock_quantity' => 3]);
        $addon = Addon::factory()->create(['store_id' => $this->store->user_id, 'stock_quantity' => 1]);
        $order = $this->order(Order::STATUS_PROCESSING);
        $item = $order->items()->create(['product_id' => $product->id, 'product_name' => 'Red roses', 'unit_price' => 100, 'quantity' => 2, 'addons_total' => 20, 'subtotal' => 220]);
        $item->addons()->create(['addon_id' => $addon->id, 'name' => 'Greeting card', 'unit_price' => 10, 'quantity' => 2, 'subtotal' => 20]);

        Livewire::test(ViewStoreOrder::class, ['record' => $order->getKey()])
            ->assertSee('Greeting card × 2')
            ->assertSee('https://www.google.com/maps?q=24.7136,46.6753')
            ->callAction('updateStatus', ['status' => Order::STATUS_CANCELLED, 'reason' => ''])
            ->assertHasActionErrors(['reason' => 'required'])
            ->callAction('updateStatus', ['status' => Order::STATUS_CANCELLED, 'reason' => 'Out of stock'])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame([Order::STATUS_CANCELLED, Order::ACTOR_SYSTEM, 'Out of stock'], [$order->status, $order->cancelled_by, $order->cancellation_reason]);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);   // refunds stay a separate step
        $this->assertSame([5, 3], [$product->fresh()->stock_quantity, $addon->fresh()->stock_quantity]);
        $this->assertSame(['notifications.order_admin_cancelled_title'], $this->notificationsOf($this->customer));

        // Finished, unpaid, or with the delivery company: nothing (or only "delivered") to set
        Livewire::test(ListStoreOrders::class)
            ->assertTableActionHidden('updateStatus', $order->getKey())
            ->assertTableActionHidden('updateStatus', $this->order(Order::STATUS_PENDING_PAYMENT, Order::PAYMENT_PENDING)->getKey());
        $this->assertSame([Order::STATUS_DELIVERED], app(\App\Services\AdminOrderStatusService::class)->storeTargets($this->order(Order::STATUS_DRIVER_ACCEPTED)));
    }

    public function test_store_order_page_shows_the_review_and_toggles_its_visibility(): void
    {
        $order = $this->order(Order::STATUS_DELIVERED);
        Review::factory()->forOrder($order)->create(['store_rating' => 4, 'store_comment' => 'Lovely flowers', 'products_rating' => 5]);
        $copy = StoreReview::create(['store_profile_id' => $this->store->id, 'user_id' => $this->customer->id, 'order_id' => $order->id, 'rating' => 4, 'comment' => 'Lovely flowers', 'is_visible' => true]);

        Livewire::test(ViewStoreOrder::class, ['record' => $order->getKey()])
            ->assertSee('Lovely flowers')
            ->assertSee('★★★★☆')
            ->assertSee('Nora')
            ->assertInfolistActionVisible('review', 'toggleReviewVisibility')
            ->callInfolistAction('review', 'toggleReviewVisibility');

        $this->assertFalse($copy->fresh()->is_visible);
        $this->get(StoreOrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertSee('Rose House');
    }

    public function test_shopper_orders_list_hides_drafts_and_shows_the_financial_columns(): void
    {
        $shopper = ShopperProfile::factory()->create(['commission_rate' => 10]);
        $paid = $this->customOrder($shopper, CustomOrder::STATUS_COMPLETED, CustomOrder::PAYMENT_PAID);
        $pending = $this->customOrder(null, CustomOrder::STATUS_PENDING);
        $draft = $this->customOrder(null, CustomOrder::STATUS_DRAFT);

        Livewire::test(ListShopperOrders::class)
            ->assertCanSeeTableRecords([$paid, $pending])
            ->assertCanNotSeeTableRecords([$draft])
            ->assertTableColumnStateSet('items_cost', 300.0, $paid->getKey())
            ->assertTableColumnStateSet('commission', 5.0, $paid->getKey())      // 10% of the 50 fee only
            ->assertTableColumnStateSet('total_amount', '425.50', $paid->getKey())
            ->filterTable('status_group', ['delivered'])
            ->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$pending])
            ->resetTableFilters()
            ->filterTable('payment_status', [CustomOrder::PAYMENT_PENDING])
            ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$paid]);

        Livewire::test(ViewShopperOrder::class, ['record' => $paid->getKey()])
            ->assertSee(__('admin.order_management.shopper_finance'))
            ->assertSee(__('admin.order_management.commission_rate', ['rate' => '10']));
    }

    public function test_admin_updates_a_shopper_order_and_both_sides_are_notified(): void
    {
        $shopper = ShopperProfile::factory()->create();
        $order = $this->customOrder($shopper, CustomOrder::STATUS_PENDING);

        Livewire::test(ViewShopperOrder::class, ['record' => $order->getKey()])
            ->callAction('updateStatus', ['status' => CustomOrder::STATUS_ACCEPTED])
            ->assertHasNoActionErrors();
        $this->assertSame(CustomOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->accepted_at);
        $this->assertSame(['notifications.custom_order_accepted_title'], $this->notificationsOf($this->customer));
        $this->assertSame(['notifications.custom_order_shopper_status_title'], $this->notificationsOf($shopper->user));

        Livewire::test(ListShopperOrders::class)
            ->callTableAction('updateStatus', $order->getKey(), ['status' => CustomOrder::STATUS_CANCELLED, 'reason' => 'Customer request'])
            ->assertHasNoTableActionErrors();
        $order->refresh();
        $this->assertSame([CustomOrder::STATUS_CANCELLED, CustomOrder::ACTOR_SYSTEM, 'Customer request'], [$order->status, $order->cancelled_by, $order->cancellation_reason]);
        $this->assertSame('notifications.custom_order_admin_cancelled_title', last($this->notificationsOf($this->customer)));

        // Paid: delivered can be set, cancelling (which would need a refund) cannot
        $paid = $this->customOrder($shopper, CustomOrder::STATUS_DRIVER_ACCEPTED, CustomOrder::PAYMENT_PAID);
        $this->assertSame([CustomOrder::STATUS_ORDER_PICKED_UP, CustomOrder::STATUS_COMPLETED], app(\App\Services\AdminOrderStatusService::class)->customTargets($paid));
        Livewire::test(ListShopperOrders::class)
            ->callTableAction('updateStatus', $paid->getKey(), ['status' => CustomOrder::STATUS_COMPLETED]);
        $this->assertSame(CustomOrder::STATUS_COMPLETED, $paid->fresh()->status);
        $this->assertNotNull($paid->fresh()->completed_at);

        // No shopper yet: nothing to confirm, only cancel
        $this->assertSame([CustomOrder::STATUS_CANCELLED], app(\App\Services\AdminOrderStatusService::class)->customTargets($this->customOrder(null, CustomOrder::STATUS_PENDING)));
    }

    public function test_shopper_order_review_visibility_toggle(): void
    {
        $shopper = ShopperProfile::factory()->create();
        $order = $this->customOrder($shopper, CustomOrder::STATUS_COMPLETED, CustomOrder::PAYMENT_PAID);
        $review = Review::factory()->forOrder($order)->create(['store_rating' => 2, 'store_comment' => 'Slow shopper', 'is_visible' => true]);

        Livewire::test(ViewShopperOrder::class, ['record' => $order->getKey()])
            ->assertSee('Slow shopper')
            ->callInfolistAction('review', 'toggleReviewVisibility')
            ->assertSee(__('admin.reviews.hidden'));

        $this->assertFalse($review->fresh()->is_visible);
    }
}
