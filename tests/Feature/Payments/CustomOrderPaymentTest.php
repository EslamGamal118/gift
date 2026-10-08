<?php

namespace Tests\Feature\Payments;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\AlRajhiEncryptionService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paying a purchased custom order (waiting_for_payment) through the existing
 * gateways - the payment completes it - priced on the server: items 2 x 50 + 1 x 30 = 130, + 20 shopper fees = 150,
 * + 25 delivery, + 15% VAT on 175 = 26.25  ->  201.25.
 */
class CustomOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected const TOTAL = '201.25';

    protected User $customer;

    protected User $shopper;

    protected CustomOrder $order;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $sent = [];

    protected bool $refundsFail = false;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests check the delivery pricing itself (with free delivery off)
        config(['checkout.free_delivery' => false]);

        foreach ([
            'ALRAJHI_BASE_URL' => 'https://alrajhi.test', 'ALRAJHI_TRANSPORTAL_ID' => 'T1', 'ALRAJHI_PASSWORD' => 'secret',
            'ALRAJHI_ENCRYPTION_KEY' => str_repeat('k', 32), 'ALRAJHI_IV' => 'iv-1234567890123',
        ] as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }

        config([
            'checkout.tax.rate' => 0.15, 'checkout.tax.prices_include_tax' => false,
            'services.alrajhi.base_url' => 'https://alrajhi.test', 'services.alrajhi.transportal_id' => 'T1',
            'services.tabby.enabled' => true, 'services.tabby.base_url' => 'https://tabby.test',
            'services.tabby.public_key' => 'pk', 'services.tabby.secret_key' => 'sk', 'services.tabby.merchant_code' => 'GIFT',
            'services.tabby.webhook_secret' => 'hook', 'services.tabby.app_return_url' => null,
            'services.tamara.enabled' => true, 'services.tamara.base_url' => 'https://tamara.test',
            'services.tamara.api_token' => 'tt', 'services.tamara.notification_token' => 'nt',
        ]);

        Queue::fake([SendPushNotification::class]);

        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed', 'phone' => '966500000001', 'email' => 'sara@example.com']);
        $this->shopper  = User::factory()->shopper()->create();

        // Purchased and invoiced: every item priced, fees and delivery set; the stored total is stale on purpose
        $this->order = CustomOrder::factory()->assignedTo($this->shopper)->create([
            'user_id' => $this->customer->id, 'status' => CustomOrder::STATUS_WAITING_FOR_PAYMENT, 'currency' => 'SAR',
        ]);
        $this->order->forceFill([
            'invoice_disk' => 'public', 'invoice_path' => 'custom-orders/invoice.jpg', 'invoice_submitted_at' => now(),
            'shopper_fees' => 20, 'delivery_fee' => 25, 'final_amount' => 150, 'total_amount' => 1,
        ])->save();
        CustomOrderItem::factory()->create(['custom_order_id' => $this->order->id, 'product_name' => 'Oud perfume', 'quantity' => 2, 'unit_price' => 50]);
        CustomOrderItem::factory()->create(['custom_order_id' => $this->order->id, 'product_name' => 'Rose bouquet', 'quantity' => 1, 'unit_price' => 30]);

        $key = 'CO-'.$this->order->id;
        Http::fake(function (Request $request) use ($key) {
            $url = $request->url();
            $this->sent[$url] = $request->data();

            return match (true) {
                // Refunds
                str_contains($url, 'tabby.test/api/v2/payments/pay-9/refunds') => $this->refundsFail
                    ? Http::response(['error' => 'payment is disputed'], 400)
                    : Http::response(['id' => 'pay-9', 'status' => 'CLOSED', 'refunds' => [['id' => 'rf-1', 'amount' => self::TOTAL]]]),
                str_contains($url, 'tamara.test/orders/tam-9/cancel') => Http::response(['cancel_id' => 'cn-1', 'order_id' => 'tam-9', 'status' => 'canceled']),
                str_contains($url, 'alrajhi.test/pg/payment/tranportal.htm') => Http::response([['status' => '1', 'result' => 'CAPTURED', 'transId' => 'RT-55']]),

                str_contains($url, 'tabby.test/api/v2/checkout') => Http::response([
                    'id' => 'sess-9', 'status' => 'created', 'payment' => ['id' => 'pay-9'],
                    'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://tabby.test/hpp']]]],
                ]),
                str_contains($url, 'tabby.test/api/v2/payments/pay-9/captures') => Http::response(['status' => 'CLOSED']),
                str_contains($url, 'tabby.test/api/v2/payments/pay-9') => Http::response([
                    'id' => 'pay-9', 'status' => 'AUTHORIZED', 'amount' => self::TOTAL, 'currency' => 'SAR',
                    'order' => ['reference_id' => $this->order->order_number], 'captures' => [],
                ]),
                str_contains($url, 'tamara.test/checkout/payment-types') => Http::response([['name' => 'PAY_NOW']]),
                str_contains($url, 'tamara.test/checkout') => Http::response(['checkout_url' => 'https://tamara.test/hpp', 'order_id' => 'tam-9', 'checkout_id' => 'chk-9']),
                str_contains($url, 'tamara.test/orders/tam-9/authorise') => Http::response(['status' => 'authorised']),
                str_contains($url, 'alrajhi.test') => Http::response([['status' => '1', 'result' => '888:https://alrajhi.test/hpp']]),
                default => Http::response([], 404),
            };
        });
    }

    protected function pay(array $data = [])
    {
        Sanctum::actingAs($this->customer);

        return $this->postJson('/api/v1/checkout/pay', $data + ['order_id' => $this->order->id, 'payment_method' => 'tabby']);
    }

    public function test_the_order_is_priced_on_the_server_with_vat_and_client_totals_are_ignored(): void
    {
        $this->pay(['payment_method' => 'tabby', 'total_amount' => 1, 'tax_amount' => 0, 'subtotal' => 1])->assertOk()
            ->assertJsonPath('message', __('checkout.payment_initiated'))
            ->assertJsonPath('data.order_type', 'custom_order')
            ->assertJsonPath('data.redirect_url', 'https://tabby.test/hpp')
            ->assertJsonPath('data.pricing.subtotal.amount', 130)
            ->assertJsonPath('data.pricing.shopper_fees.amount', 20)
            ->assertJsonPath('data.pricing.delivery_fee.amount', 25)
            ->assertJsonPath('data.pricing.tax.amount', 26.25)
            ->assertJsonPath('data.pricing.tax_rate', 0.15)
            ->assertJsonPath('data.pricing.total.amount', 201.25);

        $order = $this->order->fresh();
        $this->assertEquals(201.25, $order->total_amount);
        $this->assertEquals(26.25, $order->tax_amount);
        $this->assertEquals(150, $order->final_amount);
        $this->assertSame('tabby', $order->payment_method);

        // The customer's order shows the same breakdown and that it can be paid
        $this->getJson("/api/v1/custom-orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.pricing.tax.amount', 26.25)
            ->assertJsonPath('data.pricing.total.amount', 201.25)
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.is_payable', true);
    }

    public function test_full_flow_invoice_puts_the_order_on_waiting_for_payment_and_the_payment_completes_it(): void
    {
        Storage::fake('public');
        config(['custom_orders.delivery.fee' => 25]);   // set on the order when the invoice comes in
        $this->order->forceFill(['status' => CustomOrder::STATUS_IN_PROGRESS, 'started_at' => now(), 'invoice_submitted_at' => null, 'total_amount' => null])->save();
        [$oud, $rose] = $this->order->items()->orderBy('id')->get();

        // Shopper: "تم الشراء" with the invoice (multipart)
        Sanctum::actingAs($this->shopper);
        $this->post("/api/v1/shopper/orders/{$this->order->id}/status", [
            'status' => 'purchased',
            'invoice_image' => UploadedFile::fake()->image('invoice.jpg'),
            'pickup_address' => [
                'location_name' => 'Panorama Mall', 'city' => 'Riyadh', 'district' => 'Olaya', 'street' => 'Takhassusi St', 'building_number' => '3120',
                'latitude' => 24.6922, 'longitude' => 46.6697,
            ],
            'shopper_fees' => 20,
            'items' => [['id' => $oud->id, 'unit_price' => 50], ['id' => $rose->id, 'unit_price' => 30]],
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.status_badge.key', 'waiting_for_payment')
            ->assertJsonPath('data.pricing.total.amount', 201.25);

        $order = $this->order->fresh();
        $this->assertNotNull($order->purchased_at);
        $this->assertNull($order->completed_at);

        // Customer: told to pay, sees the order payable, pays
        $this->assertSame(['notifications.custom_order_waiting_for_payment_title'], $this->notificationsOf($this->customer));
        Sanctum::actingAs($this->customer);
        $details = $this->getJson("/api/v1/custom-orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_payment')
            ->assertJsonPath('data.payment.is_payable', true)
            ->assertJsonPath('data.invoice.image_url', fn ($url) => str_contains((string) $url, 'invoices/'))
            ->assertJsonPath('data.pickup.id', $order->pickup_address_id)
            ->assertJsonPath('data.pickup.location_name', 'Panorama Mall')
            ->assertJsonPath('data.pickup.city', 'Riyadh')
            ->assertJsonPath('data.pickup.full_address', '3120, Takhassusi St, Olaya, Riyadh')
            ->assertJsonPath('data.pickup.latitude', 24.6922)
            ->assertJsonPath('data.pickup.longitude', 46.6697)
            ->json('data.pickup');
        $this->assertArrayNotHasKey('phone', $details);   // the shopper's own number stays private

        $this->paidWithTabby();

        $order = $this->order->fresh();
        $this->assertSame([CustomOrder::STATUS_PAID, 'paid'], [$order->status, $order->payment_status]);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->completed_at);   // completed once delivered
        $this->assertSame(['notifications.custom_order_paid_title'], $this->notificationsOf($this->shopper));

        // Paid: the invoice is final
        Sanctum::actingAs($this->shopper);
        $this->post("/api/v1/shopper/orders/{$order->id}/status", ['status' => 'purchased'], ['Accept' => 'application/json'])
            ->assertStatus(409);
    }

    public function test_prices_including_vat_extract_it_from_the_total(): void
    {
        config(['checkout.tax.prices_include_tax' => true]);

        $this->pay()->assertOk()
            ->assertJsonPath('data.pricing.total.amount', 175)        // 150 + 25
            ->assertJsonPath('data.pricing.tax.amount', 22.83);       // 175 - 175 / 1.15
    }

    public function test_tabby_session_return_and_webhook_for_a_custom_order(): void
    {
        $this->pay()->assertOk()->assertJsonPath('data.reference', 'pay-9');

        $payload = $this->sent['https://tabby.test/api/v2/checkout'];
        $this->assertSame(self::TOTAL, $payload['payment']['amount']);
        $this->assertSame($this->order->order_number, $payload['payment']['order']['reference_id']);
        $this->assertSame('26.25', $payload['payment']['order']['tax_amount']);
        $this->assertSame('25.00', $payload['payment']['order']['shipping_amount']);
        $this->assertSame('CO-'.$this->order->id, $payload['payment']['meta']['order_id']);
        $this->assertSame(['Oud perfume', 'Rose bouquet', __('custom_orders.shopper_fees_line')], array_column($payload['payment']['order']['items'], 'title'));
        $this->assertSame(['50.00', '30.00', '20.00'], array_column($payload['payment']['order']['items'], 'unit_price'));
        $this->assertStringEndsWith('/payments/tabby/success/CO-'.$this->order->id, $payload['merchant_urls']['success']);

        $this->getJson('/api/v1/payments/tabby/success/CO-'.$this->order->id.'?payment_id=pay-9')->assertOk()
            ->assertJsonPath('data.order_id', $this->order->id)
            ->assertJsonPath('data.order_type', 'custom_order')
            ->assertJsonPath('data.is_paid', true);

        $order = $this->order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(CustomOrder::STATUS_PAID, $order->status);   // completed once delivered
        $this->assertNull($order->completed_at);
        $this->assertSame(['session_created', 'capture', 'captured'], $order->transactions()->orderBy('id')->pluck('event')->all());
        $this->assertSame(0, PaymentTransaction::query()->whereNotNull('order_id')->count());

        // A later webhook finds it by payment id and changes nothing
        $this->postJson('/api/v1/webhooks/tabby', ['id' => 'pay-9'], ['X-Tabby-Auth' => 'hook'])->assertOk()
            ->assertJsonPath('order_type', 'custom_order')
            ->assertJsonPath('status', 'paid');

        // Paid: not payable again
        $this->pay()->assertStatus(409)->assertJsonPath('message', __('checkout.order_not_payable'));
    }

    public function test_tamara_webhook_approves_or_fails_a_custom_order(): void
    {
        $this->pay(['payment_method' => 'tamara'])->assertOk()->assertJsonPath('data.reference', 'tam-9');

        $payload = $this->sent['https://tamara.test/checkout'];
        $this->assertSame('CO-'.$this->order->id, $payload['order_reference_id']);
        $this->assertSame(201.25, $payload['total_amount']['amount']);
        $this->assertSame('26.25', $payload['tax_amount']['amount']);
        $this->assertStringEndsWith('?order_id=CO-'.$this->order->id, $payload['merchant_url']['success']);

        $jwt = $this->jwt('nt');
        $this->postJson('/api/v1/webhooks/tamara?tamaraToken='.$jwt, [
            'event_type' => 'order_declined', 'order_reference_id' => 'CO-'.$this->order->id, 'order_id' => 'tam-9',
        ])->assertOk()->assertJsonPath('order_type', 'custom_order')->assertJsonPath('status', 'failed');
        $this->assertTrue($this->order->fresh()->isPayable());   // can retry

        $this->postJson('/api/v1/webhooks/tamara?tamaraToken='.$jwt, [
            'event_type' => 'order_approved', 'order_reference_id' => 'CO-'.$this->order->id, 'order_id' => 'tam-9',
        ])->assertOk()->assertJsonPath('status', 'paid');

        $this->assertSame('tamara', $this->order->fresh()->payment_method);
    }

    public function test_paymob_is_the_alrajhi_card_gateway_and_its_callback_pays_the_custom_order(): void
    {
        $this->pay(['payment_method' => 'paymob'])->assertOk()
            ->assertJsonPath('data.gateway', 'alrajhi')
            ->assertJsonPath('data.redirect_url', 'https://alrajhi.test/hpp?PaymentID=888');

        $plain = json_decode((new AlRajhiEncryptionService)->decrypt($this->sent['https://alrajhi.test/pg/payment/hosted.htm'][0]['trandata']), true);
        $this->assertMatchesRegularExpression('/^CO-'.$this->order->id.'_\d+$/', $plain[0]['trackId']);
        $this->assertSame(self::TOTAL, $plain[0]['amt']);

        $trandata = (new AlRajhiEncryptionService)->encrypt(urlencode(json_encode([['result' => 'CAPTURED', 'trackId' => $plain[0]['trackId']]])));
        $this->postJson('/api/v1/payments/alrajhi/callback', ['trandata' => $trandata])->assertOk()
            ->assertJsonPath('data.order_id', $this->order->id)
            ->assertJsonPath('data.order_type', 'custom_order')
            ->assertJsonPath('data.is_paid', true);

        $this->getJson("/api/v1/custom-orders/{$this->order->id}/payment-status")->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'alrajhi')
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonPath('data.is_payable', false)
            ->assertJsonPath('data.pricing.total.amount', 201.25);

        $this->assertSame(1, $this->order->transactions()->where('status', PaymentTransaction::STATUS_CAPTURED)->count());
    }

    public function test_the_delivery_address_can_be_switched_at_checkout(): void
    {
        $address = UserAddress::create([
            'user_id' => $this->customer->id, 'location_name' => 'Office', 'city' => 'Jeddah', 'district' => 'Al Rawdah',
            'street' => 'Tahlia St', 'building_number' => '7',
        ]);
        $foreign = UserAddress::create(['user_id' => User::factory()->customer()->create()->id] + $address->only(['location_name', 'city', 'district', 'street', 'building_number']));

        $this->pay(['delivery_address_id' => $foreign->id])->assertUnprocessable()
            ->assertJsonPath('data.errors.delivery_address_id.0', __('custom_orders.address_not_found'));

        $this->pay(['delivery_address_id' => $address->id])->assertOk();

        $order = $this->order->fresh();
        $this->assertSame($address->id, $order->delivery_address_id);
        $this->assertSame('7, Tahlia St, Al Rawdah, Jeddah', $order->delivery_address);
    }

    public function test_who_and_when_a_custom_order_can_be_paid(): void
    {
        // Validation
        $this->pay(['payment_method' => 'bitcoin'])->assertUnprocessable()->assertJsonValidationErrors('payment_method', 'data.errors');
        $this->pay(['order_id' => null])->assertUnprocessable()->assertJsonValidationErrors('order_id', 'data.errors');

        // Someone else's order
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->postJson('/api/v1/checkout/pay', ['order_id' => $this->order->id, 'payment_method' => 'tabby'])->assertNotFound();

        // Before the invoice, or while still shopping
        $this->order->forceFill(['invoice_submitted_at' => null])->save();
        $this->pay()->assertStatus(409)->assertJsonPath('message', __('custom_orders.not_payable_yet'));
        $this->order->forceFill(['invoice_submitted_at' => now(), 'status' => CustomOrder::STATUS_IN_PROGRESS])->save();
        $this->pay()->assertStatus(409)->assertJsonPath('message', __('custom_orders.not_payable_yet'));

        // Cancelled
        $this->order->forceFill(['invoice_submitted_at' => now(), 'status' => CustomOrder::STATUS_CANCELLED])->save();
        $this->pay()->assertStatus(409)->assertJsonPath('message', __('checkout.order_not_payable'));

        // Shoppers cannot use the customer checkout
        Sanctum::actingAs($this->shopper);
        $this->postJson('/api/v1/checkout/pay', ['order_id' => $this->order->id, 'payment_method' => 'tabby'])->assertForbidden();

        $this->assertSame([], $this->sent);   // no gateway was called
    }

    public function test_the_shopper_cannot_change_the_invoice_once_paid(): void
    {
        Storage::fake('public');
        $this->pay()->assertOk();
        $this->getJson('/api/v1/payments/tabby/success/CO-'.$this->order->id.'?payment_id=pay-9')->assertOk();

        Sanctum::actingAs($this->shopper);
        $item = $this->order->items()->first();
        $this->post("/api/v1/shopper/orders/{$this->order->id}/submit-invoice-prices", [
            'invoice_image' => UploadedFile::fake()->image('invoice.jpg'),
            'pickup_address' => ['location_name' => 'Mall', 'city' => 'Riyadh', 'district' => 'Olaya', 'street' => 'Olaya St', 'building_number' => '1'],
            'shopper_fees' => 0,
            'items' => [['id' => $item->id, 'unit_price' => 1]],
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('data.current_status', 'paid');

        $this->assertEquals(50, $item->fresh()->unit_price);
        $this->assertEquals(201.25, $this->order->fresh()->total_amount);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /*
    |--------------------------------------------------------------------------
    | After payment: the shopper is told; a cancelled paid order is refunded
    |--------------------------------------------------------------------------
    */

    /**
     * Pay with Tabby and come back through the return URL.
     */
    protected function paidWithTabby(): void
    {
        $this->pay()->assertOk();
        $this->getJson('/api/v1/payments/tabby/success/CO-'.$this->order->id.'?payment_id=pay-9')->assertOk()->assertJsonPath('data.is_paid', true);
    }

    /**
     * Paid orders are with the delivery company, so neither the customer nor the
     * shopper can cancel them any more; support / an admin still can, which refunds.
     */
    protected function cancelBySupport(): void
    {
        $this->order->fresh()->forceFill([
            'status' => CustomOrder::STATUS_CANCELLED, 'cancelled_at' => now(),
            'cancelled_by' => CustomOrder::ACTOR_SYSTEM, 'cancellation_reason' => 'Refund requested',
        ])->save();
    }

    /**
     * @return list<string>
     */
    protected function notificationsOf(User $user): array
    {
        return AppNotification::query()->with('content')->where('notifiable_id', $user->id)->orderBy('id')->get()
            ->map(fn ($n) => $n->content->title_key)->all();
    }

    public function test_the_shopper_is_notified_once_when_the_customer_pays(): void
    {
        $this->paidWithTabby();

        // The webhook repeating the payment changes nothing
        $this->postJson('/api/v1/webhooks/tabby', ['id' => 'pay-9'], ['X-Tabby-Auth' => 'hook'])->assertOk();

        $this->assertSame(['notifications.custom_order_paid_title'], $this->notificationsOf($this->shopper));
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->recipientIds === [$this->shopper->id]);

        $content = AppNotification::query()->where('notifiable_id', $this->shopper->id)->sole()->content;
        $this->assertSame((string) $this->order->id, $content->data['custom_order_id']);
        $this->assertSame('paid', $content->data['payment_status']);
        $this->assertStringContainsString('201.25', $content->body_params['amount']);
        $this->assertSame(0, $this->order->transactions()->where('event', 'like', 'refund%')->count());
    }

    public function test_a_paid_order_cancelled_by_support_is_refunded_through_tabby(): void
    {
        $this->paidWithTabby();

        // Paid: no longer cancellable by the customer or the shopper
        Sanctum::actingAs($this->shopper);
        $this->postJson("/api/v1/shopper/orders/{$this->order->id}/status", ['status' => 'reject', 'cancellation_reason' => 'Shop closed'])->assertStatus(409);
        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/custom-orders/{$this->order->id}/cancel")->assertStatus(409);
        $this->assertArrayNotHasKey('https://tabby.test/api/v2/payments/pay-9/refunds', $this->sent);

        $this->cancelBySupport();

        $refund = $this->sent['https://tabby.test/api/v2/payments/pay-9/refunds'];
        $this->assertSame(['amount' => self::TOTAL, 'reference_id' => 'REF-CO-'.$this->order->id, 'reason' => 'Custom order '.$this->order->order_number.' cancelled'], $refund);

        $order = $this->order->fresh();
        $this->assertSame(CustomOrder::PAYMENT_REFUNDED, $order->payment_status);
        $this->assertSame('rf-1', $order->payment_data['refund_reference']);
        $this->assertSame(['refund_requested', 'refund'], $order->transactions()->where('event', 'like', 'refund%')->orderBy('id')->pluck('event')->all());
        $this->assertSame(PaymentTransaction::STATUS_REFUNDED, $order->transactions()->latest('id')->first()->status);

        // The customer sees it on the order
        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/custom-orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.payment.status', 'refunded')
            ->assertJsonPath('data.payment.is_payable', false);
    }

    public function test_a_paid_order_cancelled_by_support_is_refunded_through_tamara(): void
    {
        $this->pay(['payment_method' => 'tamara'])->assertOk();
        $this->postJson('/api/v1/webhooks/tamara?tamaraToken='.$this->jwt('nt'), [
            'event_type' => 'order_approved', 'order_reference_id' => 'CO-'.$this->order->id, 'order_id' => 'tam-9',
        ])->assertOk()->assertJsonPath('status', 'paid');

        $this->cancelBySupport();

        // Authorised only (never captured): cancelled at Tamara for the full amount
        $this->assertSame(['total_amount' => ['amount' => 201.25, 'currency' => 'SAR']], $this->sent['https://tamara.test/orders/tam-9/cancel']);
        $this->assertArrayNotHasKey('https://tamara.test/payments/simplified-refund/tam-9', $this->sent);
        $this->assertSame(CustomOrder::PAYMENT_REFUNDED, $this->order->fresh()->payment_status);
        $this->assertSame('cn-1', $this->order->fresh()->payment_data['refund_reference']);
    }

    public function test_a_paid_order_is_refunded_through_alrajhi_with_the_original_transaction(): void
    {
        $this->pay(['payment_method' => 'alrajhi'])->assertOk();

        $crypto = new AlRajhiEncryptionService;
        $trackId = json_decode($crypto->decrypt($this->sent['https://alrajhi.test/pg/payment/hosted.htm'][0]['trandata']), true)[0]['trackId'];
        $trandata = $crypto->encrypt(urlencode(json_encode([['result' => 'CAPTURED', 'trackId' => $trackId, 'transId' => 'TX-77', 'paymentId' => '888']])));
        $this->postJson('/api/v1/payments/alrajhi/callback', ['trandata' => $trandata])->assertOk()->assertJsonPath('data.is_paid', true);

        $this->cancelBySupport();

        $sent = $this->sent['https://alrajhi.test/pg/payment/tranportal.htm'][0];
        $plain = json_decode($crypto->decrypt($sent['trandata']), true)[0];
        $this->assertSame(['2', 'TX-77', $trackId, self::TOTAL, '682'], [$plain['action'], $plain['transId'], $plain['trackId'], $plain['amt'], $plain['currencyCode']]);
        $this->assertSame('T1', $sent['id']);

        $this->assertSame(CustomOrder::PAYMENT_REFUNDED, $this->order->fresh()->payment_status);
    }

    public function test_a_refused_refund_is_kept_for_follow_up_without_failing_the_cancellation(): void
    {
        $this->paidWithTabby();
        $this->refundsFail = true;

        $this->cancelBySupport();

        $order = $this->order->fresh();
        $this->assertSame(CustomOrder::STATUS_CANCELLED, $order->status);
        $this->assertSame(CustomOrder::PAYMENT_REFUND_FAILED, $order->payment_status);
        $failed = $order->transactions()->latest('id')->first();
        $this->assertSame(['refund_failed', PaymentTransaction::STATUS_FAILED, 'payment is disputed'], [$failed->event, $failed->status, $failed->payload['message']]);

        // Retrying later (same idempotency key at Tabby) settles it
        $this->refundsFail = false;
        $this->assertTrue(app(PaymentService::class)->refundCustomOrderPayment($order, 'retry'));
        $this->assertSame(CustomOrder::PAYMENT_REFUNDED, $order->fresh()->payment_status);
        $this->assertFalse(app(PaymentService::class)->refundCustomOrderPayment($order, 'again'));   // never twice
    }

    public function test_a_payment_landing_after_the_cancellation_is_refunded(): void
    {
        $this->pay()->assertOk();   // the customer is on the Tabby page...

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/custom-orders/{$this->order->id}/cancel")->assertOk();
        $this->assertArrayNotHasKey('https://tabby.test/api/v2/payments/pay-9/refunds', $this->sent);   // nothing paid yet

        // ...and completes the payment anyway
        $this->getJson('/api/v1/payments/tabby/success/CO-'.$this->order->id.'?payment_id=pay-9')->assertOk();

        $order = $this->order->fresh();
        $this->assertSame(CustomOrder::STATUS_CANCELLED, $order->status);
        $this->assertSame(CustomOrder::PAYMENT_REFUNDED, $order->payment_status);
        $this->assertArrayHasKey('https://tabby.test/api/v2/payments/pay-9/refunds', $this->sent);
        $this->assertNotContains('notifications.custom_order_paid_title', $this->notificationsOf($this->shopper));
    }

    public function test_an_unpaid_order_waiting_for_payment_can_be_cancelled_and_refunds_nothing(): void
    {
        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/custom-orders/{$this->order->id}/cancel")->assertOk();

        $this->assertSame(0, $this->order->transactions()->count());
        $this->assertSame([], $this->sent);
        $this->assertSame('pending', $this->order->fresh()->payment_status);
    }

    protected function jwt(string $secret): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode(['exp' => time() + 600]));

        return $head.'.'.$body.'.'.$b64(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }
}
