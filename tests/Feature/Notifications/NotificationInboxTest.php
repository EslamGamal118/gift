<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\NotificationContent;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /notifications, POST /notifications/mark-all-read, POST /notifications/{id}/read
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-04 15:00:00');
        $this->user = User::factory()->customer()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function notify(?string $type, string $at, ?User $to = null, array $data = []): AppNotification
    {
        Carbon::setTestNow($at);
        app(NotificationService::class)->send($to ?? $this->user, 'notifications.order_accepted_title', 'notifications.order_accepted_body',
            ['order_number' => 'GFT-1'], ['store_name' => 'عطوري', 'order_number' => 'GFT-1'], $type, $data, push: false);
        Carbon::setTestNow('2026-10-04 15:00:00');

        return AppNotification::query()->latest('id')->first();
    }

    public function test_inbox_is_newest_first_with_date_groups_and_unread_counts(): void
    {
        $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 14:55:00', data: ['screen' => 'order_details', 'order_id' => '15']);
        $this->notify(NotificationContent::TYPE_WALLET_UPDATE, '2026-10-03 10:00:00');
        $old = $this->notify(NotificationContent::TYPE_ACCOUNT_STATUS, '2026-09-20 10:00:00');
        $old->markAsRead();
        $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 14:00:00', User::factory()->customer()->create());   // someone else's

        Sanctum::actingAs($this->user);
        $data = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/notifications')->assertOk()->json('data');

        $this->assertSame('all', $data['type']);
        $this->assertSame(2, $data['unread_count']);
        $this->assertSame([
            ['key' => 'all', 'label' => 'الكل', 'unread_count' => 2],
            ['key' => 'orders', 'label' => 'الطلبات', 'unread_count' => 1],
            ['key' => 'payments', 'label' => 'المدفوعات', 'unread_count' => 1],
            ['key' => 'system', 'label' => 'النظام', 'unread_count' => 0],
        ], $data['filters']);

        $this->assertCount(3, $data['items']);
        $this->assertSame(['today', 'yesterday', 'older'], array_column($data['items'], 'group'));
        $this->assertSame(['اليوم', 'أمس', 'سابقاً'], array_column($data['items'], 'group_label'));
        $this->assertSame(['orders', 'payments', 'system'], array_column($data['items'], 'type'));
        $this->assertSame(['orders', 'payments', 'account'], array_column($data['items'], 'icon'));

        $first = $data['items'][0];
        $this->assertSame('تم قبول الطلب GFT-1', $first['title']);
        $this->assertStringContainsString('عطوري', $first['body']);
        $this->assertSame('order_status', $first['event']);
        $this->assertSame(['screen' => 'order_details', 'order_id' => '15'], $first['data']);
        $this->assertFalse($first['is_read']);
        $this->assertSame('منذ 5 دقائق', $first['created_at_human']);
        $this->assertTrue($data['items'][2]['is_read']);
        $this->assertArrayHasKey('pagination', $data);
    }

    public function test_filters(): void
    {
        $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 10:00:00');
        $this->notify(NotificationContent::TYPE_GIFT, '2026-10-04 11:00:00');
        $this->notify(NotificationContent::TYPE_WALLET_UPDATE, '2026-10-04 12:00:00');
        $this->notify(NotificationContent::TYPE_NEW_OFFER, '2026-10-04 13:00:00');
        $this->notify(null, '2026-10-04 14:00:00');   // untyped -> system

        Sanctum::actingAs($this->user);

        $this->assertSame(['gift', 'order_status'], array_column($this->getJson('/api/v1/notifications?type=orders')->json('data.items'), 'event'));
        $this->assertSame(['wallet_update'], array_column($this->getJson('/api/v1/notifications?type=payments')->json('data.items'), 'event'));
        $this->assertSame([null, 'new_offer'], array_column($this->getJson('/api/v1/notifications?type=system')->json('data.items'), 'event'));
        $this->getJson('/api/v1/notifications?type=all')->assertJsonCount(5, 'data.items');
        $this->getJson('/api/v1/notifications?type=promos')->assertUnprocessable();
    }

    public function test_pagination(): void
    {
        foreach (range(1, 17) as $i) {
            $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00');
        }

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notifications')
            ->assertJsonCount(15, 'data.items')
            ->assertJsonPath('data.pagination.total', 17)
            ->assertJsonPath('data.pagination.has_more', true);
        $this->getJson('/api/v1/notifications?page=2')->assertJsonCount(2, 'data.items');
    }

    public function test_mark_all_read_only_touches_my_unread_notifications(): void
    {
        $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 10:00:00');
        $this->notify(NotificationContent::TYPE_WALLET_UPDATE, '2026-10-04 11:00:00');
        $this->notify(NotificationContent::TYPE_ACCOUNT_STATUS, '2026-10-04 12:00:00')->markAsRead();   // already read
        $other = $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 12:00:00', User::factory()->customer()->create());

        Sanctum::actingAs($this->user);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديد جميع الإشعارات كمقروءة.')
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.unread_count', 0);

        $mine = AppNotification::query()->for($this->user)->get();
        $this->assertTrue($mine->every(fn (AppNotification $n) => $n->is_read && $n->read_at !== null));
        $this->assertFalse($other->fresh()->is_read);

        $this->postJson('/api/v1/notifications/mark-all-read')->assertJsonPath('data.updated_count', 0);
    }

    public function test_mark_one_as_read(): void
    {
        $first = $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 10:00:00');
        $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 11:00:00');
        $other = $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 12:00:00', User::factory()->customer()->create());

        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk()
            ->assertJsonPath('data.notification.is_read', true)
            ->assertJsonPath('data.unread_count', 1);
        $this->postJson("/api/v1/notifications/{$other->id}/read")->assertNotFound();
    }

    public function test_every_account_type_has_an_inbox_but_guests_do_not(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        $store = User::factory()->storeOwner()->create();
        $this->notify(NotificationContent::TYPE_ORDER_STATUS, '2026-10-04 10:00:00', $store);
        Sanctum::actingAs($store);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.unread_count', 1);
    }
}
