<?php

namespace Tests\Feature\Gifts;

use App\Models\Category;
use App\Models\Gift;
use App\Models\NotificationContent;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\AlRajhiService;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlineGiftsTest extends TestCase
{
    use RefreshDatabase;

    protected const CHECKOUT = '/api/v1/gifts/checkout';

    protected const EVOLUTION = 'https://evo.test';

    protected User $sender;

    protected StoreProfile $store;

    protected Product $gift;

    protected bool $whatsAppDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->customer()->create(['name' => 'Sara Ahmed', 'phone' => '966500000001']);
        $this->store = StoreProfile::factory()->approved()->create(['store_name' => 'Rose Shop']);
        $this->gift = Product::factory()->create([
            'store_id' => $this->store->user_id,
            'category_id' => Category::factory()->special()->create()->id,
            'name' => 'Spa Voucher',
            'price' => 200,
            'stock_quantity' => 5,
        ]);

        config([
            'services.alrajhi.base_url' => 'https://alrajhi.test',
            'services.alrajhi.transportal_id' => 'T1',
            'services.evolution' => ['enabled' => true, 'url' => self::EVOLUTION, 'key' => 'secret', 'instance' => 'gifts', 'timeout' => 5],
            'gifts.app_link' => 'https://gift.app/open',
        ]);

        $this->mock(AlRajhiService::class, fn ($mock) => $mock->shouldReceive('sendPayment')->andReturn(['success' => true, 'url' => 'https://alrajhi.test/pay?PaymentID=42']));
        Http::fake([self::EVOLUTION.'/*' => fn () => $this->whatsAppDown
            ? Http::response(['error' => 'instance offline'], 500)
            : Http::response(['key' => ['id' => 'wa-1']], 201)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'product_id' => $this->gift->id,
            'recipient_name' => 'Noura',
            'recipient_phone' => '0555123456',
            'recipient_email' => 'Noura@Example.com',
            'gift_message' => 'Happy birthday Noura!',
            'gateway' => 'alrajhi',
        ];
    }

    protected function buyGift(array $overrides = []): Gift
    {
        Sanctum::actingAs($this->sender);

        $id = $this->postJson(self::CHECKOUT, $this->payload($overrides))->assertCreated()->json('data.gift.id');

        return Gift::query()->findOrFail($id);
    }

    /**
     * What every gateway callback / webhook ends up calling on success.
     */
    protected function confirmPayment(Gift $gift): void
    {
        $this->assertTrue(app(PaymentService::class)->completeOrderPayment($gift->order, ['result' => 'CAPTURED'], 'alrajhi', 'PAY-42'));
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    public function test_checkout_creates_an_unpaid_gift_order_and_returns_the_payment_page(): void
    {
        Sanctum::actingAs($this->sender);

        $response = $this->postJson(self::CHECKOUT, $this->payload(['quantity' => 2]))
            ->assertCreated()
            ->assertJsonPath('data.redirect_url', 'https://alrajhi.test/pay?PaymentID=42')
            ->assertJsonPath('data.gateway', 'alrajhi')
            ->assertJsonPath('data.gift.payment_status', 'pending')
            ->assertJsonPath('data.gift.product.name', 'Spa Voucher')
            ->assertJsonPath('data.gift.product.quantity', 2)
            ->assertJsonPath('data.gift.recipient.phone', '966555123456')
            ->assertJsonPath('data.gift.recipient.email', 'noura@example.com')
            ->assertJsonPath('data.gift.store.name', 'Rose Shop');

        $gift = Gift::query()->sole();
        $order = $gift->order;
        $totals = app(CheckoutService::class)->totals(400, 0, 0, 0);

        $this->assertSame($response->json('data.order_id'), $order->id);
        $this->assertSame($this->sender->id, $order->user_id);
        $this->assertSame($this->store->user_id, $order->store_id);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame('alrajhi', $order->payment_method);
        $this->assertEquals(0, $order->delivery_fee);
        $this->assertEquals($totals['total'], $order->total_amount);
        $this->assertSame('Happy birthday Noura!', $order->gift_message);
        // The payer is the sender (gateways run their buyer checks on it)
        $this->assertSame('966500000001', $order->shipping_phone);

        $this->assertSame(3, $this->gift->fresh()->stock_quantity);
        $this->assertSame('alrajhi', $gift->payment_gateway);
        $this->assertFalse($gift->is_claimed);
        $this->assertSame(10, strlen($gift->claim_code));
        $this->assertDatabaseHas('payment_transactions', ['order_id' => $order->id, 'event' => 'session_created']);

        // Nothing is sent before the payment is confirmed
        Http::assertNothingSent();
        $this->assertSame(0, NotificationContent::query()->count());
    }

    public function test_only_special_category_products_in_stock_can_be_gifted(): void
    {
        Sanctum::actingAs($this->sender);
        $regular = Product::factory()->create(['store_id' => $this->store->user_id]);

        $this->postJson(self::CHECKOUT, $this->payload(['product_id' => $regular->id]))
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.product_id.0', __('gifts.not_giftable'));

        $this->postJson(self::CHECKOUT, $this->payload(['quantity' => 6]))->assertUnprocessable();
        $this->postJson(self::CHECKOUT, $this->payload(['recipient_phone' => '12345']))
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['recipient_phone']]]);
        $this->postJson(self::CHECKOUT, $this->payload(['gateway' => 'paypal']))
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['gateway']]]);

        $this->assertSame(0, Gift::query()->count());
        $this->assertSame(5, $this->gift->fresh()->stock_quantity);
    }

    public function test_checkout_requires_a_signed_in_customer(): void
    {
        $this->postJson(self::CHECKOUT, $this->payload())->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Payment confirmed
    |--------------------------------------------------------------------------
    */

    public function test_paid_gift_for_an_existing_customer_is_attached_and_everyone_is_notified(): void
    {
        $recipient = User::factory()->customer()->create(['phone' => '966555123456', 'name' => 'Noura']);
        $gift = $this->buyGift();

        $this->confirmPayment($gift);
        $gift->refresh();

        $this->assertSame('paid', $gift->payment_status);
        $this->assertNotNull($gift->paid_at);
        $this->assertTrue($gift->is_claimed);
        $this->assertSame($recipient->id, $gift->recipient_id);

        // WhatsApp to the recipient: sender, gift, personal message, app link
        $this->assertSame(Gift::WHATSAPP_SENT, $gift->whatsapp_status);
        Http::assertSent(function (HttpRequest $request) use ($gift) {
            return $request->url() === self::EVOLUTION.'/message/sendText/gifts'
                && $request->hasHeader('apikey', 'secret')
                && $request['number'] === '966555123456'
                && str_contains($request['text'], 'Sara Ahmed')
                && str_contains($request['text'], 'Spa Voucher')
                && str_contains($request['text'], 'Happy birthday Noura!')
                && str_contains($request['text'], 'https://gift.app/open?gift='.$gift->claim_code);
        });

        // Store: the gift notification (Arabic text as specified), not the generic one
        $storeNote = NotificationContent::query()
            ->where('title_key', 'notifications.gift_purchased_title')
            ->whereHas('notifications', fn ($q) => $q->where('notifiable_id', $this->store->user_id))
            ->sole();
        $this->assertSame(
            'تم شراء هدية جديدة Spa Voucher للمستلم Noura رقم الجوال 966555123456 مع رسالة: Happy birthday Noura!',
            $storeNote->renderBody('ar'),
        );
        $this->assertSame((string) $gift->order_id, $storeNote->data['order_id']);
        $this->assertSame(0, NotificationContent::query()->where('title_key', 'notifications.new_order_title')->count());

        // Recipient's account is told too
        $this->assertTrue(NotificationContent::query()
            ->where('title_key', 'notifications.gift_received_title')
            ->whereHas('notifications', fn ($q) => $q->where('notifiable_id', $recipient->id))
            ->exists());
    }

    public function test_gift_for_a_new_number_waits_and_is_claimed_when_the_customer_registers(): void
    {
        $gift = $this->buyGift(['gift_message' => null]);
        $this->confirmPayment($gift);

        $gift->refresh();
        $this->assertSame('paid', $gift->payment_status);
        $this->assertFalse($gift->is_claimed);
        $this->assertNull($gift->recipient_id);
        $this->assertSame(Gift::WHATSAPP_SENT, $gift->whatsapp_status);

        $storeNote = NotificationContent::query()->where('title_key', 'notifications.gift_purchased_title')->sole();
        $this->assertSame('تم شراء هدية جديدة Spa Voucher للمستلم Noura رقم الجوال 966555123456', $storeNote->renderBody('ar'));

        // A store account with that number does not claim it
        User::factory()->storeOwner()->create(['phone' => '966555123456']);
        $this->assertFalse($gift->fresh()->is_claimed);

        // The customer signs up with the number
        $recipient = User::factory()->customer()->create(['phone' => '966555123456']);

        $gift->refresh();
        $this->assertTrue($gift->is_claimed);
        $this->assertSame($recipient->id, $gift->recipient_id);

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/gifts/received')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.gift_id', $gift->id)
            ->assertJsonPath('data.items.0.sender_name', 'Sara Ahmed')
            ->assertJsonPath('data.items.0.status.key', 'active')
            ->assertJsonMissingPath('data.items.0.claim_url');
    }

    public function test_unpaid_gifts_are_never_claimed(): void
    {
        $gift = $this->buyGift();

        User::factory()->customer()->create(['phone' => '966555123456']);

        $this->assertFalse($gift->fresh()->is_claimed);
    }

    public function test_a_whatsapp_outage_does_not_break_the_payment_confirmation(): void
    {
        $this->whatsAppDown = true;
        $gift = $this->buyGift();

        $this->confirmPayment($gift);
        $gift->refresh();

        $this->assertSame('paid', $gift->payment_status);
        $this->assertSame(Order::PAYMENT_PAID, $gift->order->payment_status);
        $this->assertSame(Gift::WHATSAPP_FAILED, $gift->whatsapp_status);
        $this->assertNotNull($gift->whatsapp_error);
        // The store is still told to prepare it
        $this->assertSame(1, NotificationContent::query()->where('title_key', 'notifications.gift_purchased_title')->count());
    }

    public function test_whatsapp_is_skipped_when_not_configured(): void
    {
        config(['services.evolution.url' => null]);
        $gift = $this->buyGift();

        $this->confirmPayment($gift);

        $this->assertSame(Gift::WHATSAPP_SKIPPED, $gift->fresh()->whatsapp_status);
        Http::assertNothingSent();
    }

    public function test_a_repeated_webhook_notifies_only_once(): void
    {
        $gift = $this->buyGift();

        $this->confirmPayment($gift);
        $this->confirmPayment($gift->fresh());

        Http::assertSentCount(1);
        $this->assertSame(1, NotificationContent::query()->where('title_key', 'notifications.gift_purchased_title')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Failure / cancel
    |--------------------------------------------------------------------------
    */

    public function test_failed_and_cancelled_payments_are_mirrored_on_the_gift(): void
    {
        $gift = $this->buyGift();
        $payments = app(PaymentService::class);

        $payments->failOrderPayment($gift->order, [], 'alrajhi');
        $this->assertSame('failed', $gift->fresh()->payment_status);

        $payments->cancelOrder($gift->order->fresh(), 'Changed my mind');
        $this->assertSame('cancelled', $gift->fresh()->payment_status);
        $this->assertSame(5, $this->gift->fresh()->stock_quantity);
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | Lists & store view
    |--------------------------------------------------------------------------
    */

    public function test_sender_lists_sent_gifts_with_the_claim_link_once_paid(): void
    {
        $gift = $this->buyGift();

        $this->getJson('/api/v1/gifts/sent')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $gift->id)
            ->assertJsonPath('data.items.0.is_paid', false)
            ->assertJsonMissingPath('data.items.0.claim_url');

        $this->confirmPayment($gift);

        $this->getJson('/api/v1/gifts/sent')
            ->assertOk()
            ->assertJsonPath('data.items.0.claim_url', 'https://gift.app/open?gift='.$gift->claim_code)
            ->assertJsonPath('data.items.0.whatsapp_status', 'sent');
    }

    public function test_store_sees_the_recipient_on_the_order(): void
    {
        $gift = $this->buyGift();
        $this->confirmPayment($gift);

        Sanctum::actingAs($this->store->user);

        $this->getJson("/api/v1/store/orders/{$gift->order_id}")
            ->assertOk()
            ->assertJsonPath('data.is_online_gift', true)
            ->assertJsonPath('data.gift.recipient_name', 'Noura')
            ->assertJsonPath('data.gift.recipient_phone', '966555123456')
            ->assertJsonPath('data.gift.message', 'Happy birthday Noura!');
    }
}
