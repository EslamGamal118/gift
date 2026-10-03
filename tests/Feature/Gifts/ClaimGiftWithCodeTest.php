<?php

namespace Tests\Feature\Gifts;

use App\Models\Gift;
use App\Models\Order;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\GiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "استلام هدية": a recipient registered with another phone claims the gift with
 * the 6-digit code texted to the phone it was sent to.
 */
class ClaimGiftWithCodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $sender;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 10:00:00');
        Http::fake([
            'sms.test/*' => Http::response(['code' => 200, 'message' => 'ok', 'job_id' => 'J1']),
            '*' => Http::response(),
        ]);

        $this->sender = User::factory()->customer()->create(['name' => 'اسماء محمد', 'phone' => '966500000001']);
        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A gift to this phone, paid through GiftService::handlePaid().
     */
    protected function paidGift(string $phone = '966511111111'): Gift
    {
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $this->sender->id, 'store_id' => $this->store->id,
            'shipping_name' => 'x', 'shipping_city' => '-', 'shipping_district' => '-', 'shipping_street' => '-',
            'shipping_building_number' => '-', 'shipping_address' => '-',
            'currency' => 'SAR', 'subtotal' => 100, 'total_amount' => 115,
            'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ]);
        $order->items()->create(['product_name' => 'اشتراك شهري - صالة ألعاب', 'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100]);

        $gift = Gift::query()->forceCreate([
            'order_id' => $order->id, 'sender_id' => $this->sender->id, 'store_id' => $this->store->id,
            'recipient_name' => 'سارة', 'recipient_phone' => $phone,
            'payment_status' => Order::PAYMENT_PENDING, 'claim_code' => Gift::generateClaimCode(),
        ]);

        app(GiftService::class)->handlePaid($order);

        return $gift->fresh();
    }

    public function test_payment_gives_an_unclaimed_gift_a_six_digit_code(): void
    {
        $gift = $this->paidGift();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $gift->claim_pin);
        $this->assertFalse($gift->is_claimed);
        $this->assertSame(Gift::SMS_SKIPPED, $gift->sms_status);   // no real SMS in testing
        $this->assertStringContainsString('رمز استلام الهدية: '.$gift->claim_pin, app(GiftService::class)->whatsAppMessage($gift));
        $this->assertStringContainsString($gift->claim_pin, app(GiftService::class)->smsMessage($gift));

        // Already in the account registered with that phone: no code, no SMS
        $owner = User::factory()->customer()->create(['phone' => '966522222222']);
        $attached = $this->paidGift('966522222222');
        $this->assertSame($owner->id, $attached->recipient_id);
        $this->assertNull($attached->claim_pin);
        $this->assertSame(Gift::SMS_SKIPPED, $attached->sms_status);
    }

    public function test_the_sms_goes_to_the_recipients_phone_outside_testing_environments(): void
    {
        config([
            'otp.testing_environments' => [],
            'services.forjawaly' => ['url' => 'https://sms.test/send', 'key' => 'k', 'secret' => 's', 'sender' => 'Tahaadoo'],
        ]);

        $gift = $this->paidGift();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://sms.test/send'
            && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), $gift->claim_pin)
            && str_contains(json_encode($request->data()), '966511111111'));
        $this->assertSame(Gift::SMS_SENT, $gift->fresh()->sms_status);
    }

    public function test_another_account_claims_it_with_the_code(): void
    {
        $gift = $this->paidGift();
        $recipient = User::factory()->customer()->create(['phone' => '966533333333']);   // registered with another phone
        Sanctum::actingAs($recipient);

        // Arabic-Indic digits and spaces are fine
        $arabic = strtr(substr($gift->claim_pin, 0, 3).' '.substr($gift->claim_pin, 3), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/gifts/claim', ['code' => $arabic])
            ->assertOk()
            ->assertJsonPath('message', 'تم استلام الهدية بنجاح، تجدها الآن في هداياك المتاحة.')
            ->assertJsonPath('data.gift_id', $gift->id)
            ->assertJsonPath('data.status.tab', 'available')
            ->assertJsonPath('data.sender_name', 'اسماء محمد');

        $gift->refresh();
        $this->assertSame($recipient->id, $gift->recipient_id);
        $this->assertTrue($gift->is_claimed);

        $this->getJson('/api/v1/gifts/received?tab=available')->assertJsonPath('data.items.0.gift_id', $gift->id);

        // Claiming again from the same account is harmless
        $this->postJson('/api/v1/gifts/claim', ['code' => $gift->claim_pin])->assertOk();

        // ...but nobody else can take it any more
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson('/api/v1/gifts/claim', ['code' => $gift->claim_pin])->assertStatus(409);
    }

    public function test_wrong_own_or_expired_codes_are_refused(): void
    {
        $gift = $this->paidGift();
        $wrong = $gift->claim_pin === '000000' ? '111111' : '000000';

        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson('/api/v1/gifts/claim', ['code' => $wrong])->assertStatus(422)->assertJsonPath('message', __('gifts.claim.invalid_code'));
        $this->postJson('/api/v1/gifts/claim', ['code' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('code', 'data.errors');
        $this->postJson('/api/v1/gifts/claim', [])->assertUnprocessable()->assertJsonValidationErrors('code', 'data.errors');

        Sanctum::actingAs($this->sender);
        $this->postJson('/api/v1/gifts/claim', ['code' => $gift->claim_pin])->assertStatus(422)->assertJsonPath('message', __('gifts.claim.own_gift'));

        $gift->forceFill(['expires_at' => now()->subMinute()])->save();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson('/api/v1/gifts/claim', ['code' => $gift->claim_pin])->assertStatus(409)->assertJsonPath('message', __('gifts.claim.expired'));

        $this->assertFalse($gift->fresh()->is_claimed);
    }

    public function test_guessing_is_rate_limited_and_only_customers_can_claim(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/gifts/claim', ['code' => '999999'])->assertStatus(422);
        }
        $this->postJson('/api/v1/gifts/claim', ['code' => '999999'])->assertStatus(429);

        Sanctum::actingAs($this->store);
        $this->postJson('/api/v1/gifts/claim', ['code' => '999999'])->assertForbidden();
    }
}
