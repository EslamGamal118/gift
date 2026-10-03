<?php

namespace Tests\Feature\Gifts;

use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\GiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Online gifts" screen (tabs + details with the QR code) and redeeming the QR at the store.
 */
class ReceivedGiftDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected User $recipient;

    protected User $sender;

    protected User $store;

    protected StoreProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 10:00:00');
        Http::fake();
        config(['gifts.validity_days' => 30]);

        $this->recipient = User::factory()->customer()->create(['phone' => '966500000009']);
        $this->sender = User::factory()->customer()->create(['name' => 'اسماء محمد']);
        $this->store = User::factory()->storeOwner()->create();
        $this->profile = StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'store_name' => 'صاله الالعاب', 'rating_avg' => 4.46]);
        StoreBranch::factory()->create(['store_profile_id' => $this->profile->id, 'address' => 'شارع العليا العام، حي العليا، الرياض', 'is_main' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An unpaid gift whose order is then paid through GiftService::handlePaid().
     */
    protected function paidGift(?Product $product = null): Gift
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $this->sender->id, 'store_id' => $this->store->id,
            'shipping_name' => 'x', 'shipping_city' => '-', 'shipping_district' => '-', 'shipping_street' => '-',
            'shipping_building_number' => '-', 'shipping_address' => '-',
            'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ]);
        $order->items()->create(['product_name' => 'اشتراك شهري - صالة ألعاب', 'product_image' => 'products/arcade.jpg', 'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100]);

        $gift = Gift::query()->forceCreate([
            'order_id' => $order->id, 'sender_id' => $this->sender->id, 'store_id' => $this->store->id, 'product_id' => $product?->id,
            'recipient_name' => 'سارة', 'recipient_phone' => '966500000009',
            'gift_message' => 'أتمنى لك وقت ممتع', 'payment_status' => Order::PAYMENT_PENDING, 'claim_code' => Gift::generateClaimCode(),
        ]);

        app(GiftService::class)->handlePaid($order);

        return $gift->fresh();
    }

    public function test_payment_sets_the_qr_code_and_the_expiry(): void
    {
        $default = $this->paidGift();
        $this->assertSame(16, strlen($default->redemption_code));
        $this->assertNotSame($default->claim_code, $default->redemption_code);
        $this->assertSame('2026-10-01 23:59:59', $default->expires_at->format('Y-m-d H:i:s'));   // 30 days

        $product = Product::factory()->create(['store_id' => $this->store->id, 'gift_validity_days' => 7]);
        $this->assertSame('2026-09-08', $this->paidGift($product)->expires_at->toDateString());
    }

    public function test_details_screen(): void
    {
        $gift = $this->paidGift();
        Sanctum::actingAs($this->recipient);

        $data = $this->withHeader('Accept-Language', 'ar')->getJson("/api/v1/gifts/received/{$gift->id}")->assertOk()->json('data');

        $this->assertSame($gift->id, $data['gift_id']);
        $this->assertSame('اشتراك شهري - صالة ألعاب', $data['item_name']);
        $this->assertSame(['value' => $gift->redemption_code, 'format' => 'qr'], $data['qr_code']);
        $this->assertSame(['id' => $this->profile->id, 'name' => 'صاله الالعاب', 'logo' => $data['store']['logo'], 'rating' => 4.5], $data['store']);
        $this->assertSame('شارع العليا العام، حي العليا، الرياض', $data['address']);
        $this->assertSame('اسماء محمد', $data['sender_name']);
        $this->assertSame('أتمنى لك وقت ممتع', $data['gift_message']);
        $this->assertSame('1 أكتوبر 2026', $data['expiry_date']);
        $this->assertSame(['key' => 'active', 'label' => 'متاحة', 'tab' => 'available'], $data['status']);

        // "Open my gift" returns the same details
        $this->postJson("/api/v1/gifts/{$gift->id}/open")->assertOk()
            ->assertJsonPath('data.qr_code.value', $gift->redemption_code)
            ->assertJsonPath('data.is_opened', true);

        // Not the sender's to see, nor anyone else's
        Sanctum::actingAs($this->sender);
        $this->getJson("/api/v1/gifts/received/{$gift->id}")->assertNotFound();
    }

    public function test_tabs_split_available_from_redeemed_and_expired(): void
    {
        $active = $this->paidGift();
        $redeemed = $this->paidGift();
        $redeemed->forceFill(['redeemed_at' => now()])->save();
        $expired = $this->paidGift();
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        Sanctum::actingAs($this->recipient);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/gifts/received?tab=available')->assertOk()
            ->assertJsonPath('data.title', 'هدايا اونلاين')
            ->assertJsonPath('data.tabs', [
                ['key' => 'available', 'label' => 'المتاحة', 'count' => 1],
                ['key' => 'finished', 'label' => 'المنتهية', 'count' => 2],
            ])
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.gift_id', $active->id);

        $finished = $this->getJson('/api/v1/gifts/received?tab=finished')->assertOk()->json('data.items');
        $this->assertEqualsCanonicalizing([$redeemed->id, $expired->id], array_column($finished, 'gift_id'));
        $this->assertEqualsCanonicalizing(['redeemed', 'expired'], array_column(array_column($finished, 'status'), 'key'));
        $this->assertSame([null, null], array_column($finished, 'qr_code'));

        $this->getJson('/api/v1/gifts/received')->assertJsonCount(3, 'data.items');
        $this->getJson('/api/v1/gifts/received?tab=old')->assertUnprocessable();
    }

    public function test_the_store_redeems_the_qr_once(): void
    {
        $gift = $this->paidGift();
        Sanctum::actingAs($this->store);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/store/gifts/redeem', ['code' => strtolower($gift->redemption_code)])
            ->assertOk()
            ->assertJsonPath('message', 'تم استخدام الهدية بنجاح.')
            ->assertJsonPath('data.gift_id', $gift->id)
            ->assertJsonPath('data.item_name', 'اشتراك شهري - صالة ألعاب')
            ->assertJsonPath('data.recipient_name', 'سارة')
            ->assertJsonPath('data.sender_name', 'اسماء محمد');

        $this->assertSame(Gift::STATUS_REDEEMED, $gift->fresh()->status());
        $this->postJson('/api/v1/store/gifts/redeem', ['code' => $gift->redemption_code])->assertStatus(409);

        Sanctum::actingAs($this->recipient);
        $this->getJson("/api/v1/gifts/received/{$gift->id}")
            ->assertJsonPath('data.status.key', 'redeemed')
            ->assertJsonPath('data.qr_code', null);
    }

    public function test_redeeming_is_refused_for_expired_unknown_or_other_stores_codes(): void
    {
        $gift = $this->paidGift();
        $other = User::factory()->storeOwner()->create();

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/store/gifts/redeem', ['code' => $gift->redemption_code])->assertNotFound();

        Sanctum::actingAs($this->store);
        $this->postJson('/api/v1/store/gifts/redeem', ['code' => 'NOPE'])->assertNotFound();
        $this->postJson('/api/v1/store/gifts/redeem', [])->assertUnprocessable();

        Carbon::setTestNow('2026-10-02 00:00:01');
        $this->postJson('/api/v1/store/gifts/redeem', ['code' => $gift->redemption_code])->assertStatus(409);
        $this->assertNull($gift->fresh()->redeemed_at);

        Sanctum::actingAs($this->recipient);
        $this->postJson('/api/v1/store/gifts/redeem', ['code' => $gift->redemption_code])->assertForbidden();
    }
}
