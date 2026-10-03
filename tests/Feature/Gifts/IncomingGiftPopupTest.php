<?php

namespace Tests\Feature\Gifts;

use App\Models\Gift;
use App\Models\Order;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Home screen "وصلك هدية جديدة" popup and its two buttons.
 */
class IncomingGiftPopupTest extends TestCase
{
    use RefreshDatabase;

    protected const HOME = '/api/v1/home?latitude=24.7136&longitude=46.6753';

    protected User $recipient;

    protected User $sender;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stores.home.cache_ttl' => 0]);

        $this->recipient = User::factory()->customer()->create(['phone' => '966500000009']);
        $this->sender = User::factory()->customer()->create(['name' => 'أسماء أحمد']);
        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'عطوري']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function gift(array $attributes = [], string $paidAt = '2026-10-04 09:00:00'): Gift
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $this->sender->id, 'store_id' => $this->store->id,
            'shipping_name' => 'x', 'shipping_city' => '-', 'shipping_district' => '-', 'shipping_street' => '-',
            'shipping_building_number' => '-', 'shipping_address' => '-',
            'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $order->items()->create(['product_name' => 'بطاقة هدية رقمية', 'product_image' => 'products/card.jpg', 'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100]);

        return Gift::query()->forceCreate($attributes + [
            'order_id' => $order->id, 'sender_id' => $this->sender->id, 'store_id' => $this->store->id,
            'recipient_name' => 'سارة', 'recipient_phone' => '966500000009', 'recipient_id' => $this->recipient->id,
            'gift_message' => 'كل عام وأنتِ بخير', 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => Carbon::parse($paidAt),
            'is_claimed' => true, 'claimed_at' => now(), 'claim_code' => Gift::generateClaimCode(),
        ]);
    }

    public function test_home_shows_the_newest_unopened_gift_with_both_buttons(): void
    {
        $this->gift([], '2026-10-03 09:00:00');
        $newest = $this->gift(['gift_message' => 'مبروك التخرج'], '2026-10-04 09:00:00');

        Sanctum::actingAs($this->recipient);

        $this->withHeader('Accept-Language', 'ar')->getJson(self::HOME)
            ->assertOk()
            ->assertJsonPath('data.incoming_gift.has_gift', true)
            ->assertJsonPath('data.incoming_gift.unopened_count', 2)
            ->assertJsonPath('data.incoming_gift.gift_details.gift_id', $newest->id)
            ->assertJsonPath('data.incoming_gift.gift_details.title', 'وصلك هدية جديدة')
            ->assertJsonPath('data.incoming_gift.gift_details.subtitle', 'هدية من أسماء أحمد بانتظارك، افتحها الآن!')
            ->assertJsonPath('data.incoming_gift.gift_details.sender.name', 'أسماء أحمد')
            ->assertJsonPath('data.incoming_gift.gift_details.message', 'مبروك التخرج')
            ->assertJsonPath('data.incoming_gift.gift_details.product.name', 'بطاقة هدية رقمية')
            ->assertJsonPath('data.incoming_gift.gift_details.store_name', 'عطوري')
            ->assertJsonPath('data.incoming_gift.gift_details.actions.open', ['label' => 'افتح هديتي', 'method' => 'POST', 'endpoint' => "/api/v1/gifts/{$newest->id}/open"])
            ->assertJsonPath('data.incoming_gift.gift_details.actions.dismiss.label', 'ليس الآن');
    }

    public function test_opening_ends_the_popup_and_returns_the_gift(): void
    {
        $gift = $this->gift();
        Sanctum::actingAs($this->recipient);

        $this->postJson("/api/v1/gifts/{$gift->id}/open")->assertOk()
            ->assertJsonPath('data.gift_id', $gift->id)
            ->assertJsonPath('data.is_opened', true)
            ->assertJsonPath('data.gift_message', 'كل عام وأنتِ بخير');
        $openedAt = $gift->fresh()->opened_at;
        $this->assertNotNull($openedAt);

        // Idempotent
        Carbon::setTestNow(now()->addHour());
        $this->postJson("/api/v1/gifts/{$gift->id}/open")->assertOk();
        $this->assertTrue($openedAt->equalTo($gift->fresh()->opened_at));
        Carbon::setTestNow();

        $this->getJson(self::HOME)
            ->assertJsonPath('data.incoming_gift', ['has_gift' => false, 'unopened_count' => 0, 'gift_details' => null]);
    }

    public function test_not_now_moves_on_to_the_next_gift_but_keeps_it_unopened(): void
    {
        $older = $this->gift([], '2026-10-03 09:00:00');
        $newest = $this->gift([], '2026-10-04 09:00:00');
        Sanctum::actingAs($this->recipient);

        $this->postJson("/api/v1/gifts/{$newest->id}/dismiss")->assertOk()->assertJsonPath('data.popup_dismissed', true);

        $this->getJson(self::HOME)
            ->assertJsonPath('data.incoming_gift.gift_details.gift_id', $older->id)
            ->assertJsonPath('data.incoming_gift.unopened_count', 2);

        $this->postJson("/api/v1/gifts/{$older->id}/dismiss")->assertOk();
        $this->getJson(self::HOME)
            ->assertJsonPath('data.incoming_gift.has_gift', false)
            ->assertJsonPath('data.incoming_gift.unopened_count', 2);

        // Still listed (unopened) among the received gifts
        $this->getJson('/api/v1/gifts/received')->assertJsonPath('data.items.0.is_opened', false);
    }

    public function test_no_popup_for_guests_unpaid_gifts_or_someone_elses_gift(): void
    {
        $this->getJson(self::HOME)->assertOk()
            ->assertJsonPath('data.incoming_gift', ['has_gift' => false, 'unopened_count' => 0, 'gift_details' => null]);

        $unpaid = $this->gift(['payment_status' => Order::PAYMENT_PENDING, 'paid_at' => null]);
        Sanctum::actingAs($this->recipient);
        $this->getJson(self::HOME)->assertJsonPath('data.incoming_gift.has_gift', false);
        $this->postJson("/api/v1/gifts/{$unpaid->id}/open")->assertNotFound();

        $mine = $this->gift();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson("/api/v1/gifts/{$mine->id}/open")->assertNotFound();
        $this->postJson("/api/v1/gifts/{$mine->id}/dismiss")->assertNotFound();
        $this->assertNull($mine->fresh()->opened_at);
    }
}
