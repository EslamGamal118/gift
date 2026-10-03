<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\AlRajhiEncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Store orders through the real gateway services (HTTP faked): what each
 * gateway is sent, and that its return URL / webhook / callback marks the
 * order paid and logs the transactions.
 */
class StoreOrderGatewaysTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected Order $order;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 12:00:00');

        foreach ([
            'ALRAJHI_BASE_URL' => 'https://alrajhi.test', 'ALRAJHI_TRANSPORTAL_ID' => 'T1', 'ALRAJHI_PASSWORD' => 'secret',
            'ALRAJHI_ENCRYPTION_KEY' => str_repeat('k', 32), 'ALRAJHI_IV' => 'iv-1234567890123',
        ] as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }

        config([
            'services.alrajhi.base_url' => 'https://alrajhi.test', 'services.alrajhi.transportal_id' => 'T1',
            'services.tabby.enabled' => true, 'services.tabby.base_url' => 'https://tabby.test',
            'services.tabby.public_key' => 'pk', 'services.tabby.secret_key' => 'sk', 'services.tabby.merchant_code' => 'GIFT',
            'services.tabby.webhook_secret' => 'hook', 'services.tabby.app_return_url' => null,
            'services.tamara.enabled' => true, 'services.tamara.base_url' => 'https://tamara.test',
            'services.tamara.api_token' => 'tt', 'services.tamara.notification_token' => 'nt',
        ]);

        $this->customer = User::factory()->customer()->create(['name' => 'Sara Ahmed', 'phone' => '966500000001', 'email' => 'sara@example.com']);
        $store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $store->id]);

        $this->order = Order::create([
            'order_number' => 'GFT-TEST-1', 'user_id' => $this->customer->id, 'store_id' => $store->id,
            'shipping_name' => 'Sara Ahmed', 'shipping_phone' => '966500000001', 'shipping_email' => 'sara@example.com',
            'shipping_city' => 'Riyadh', 'shipping_district' => 'Olaya', 'shipping_street' => 'King Fahd Rd',
            'shipping_building_number' => '12', 'shipping_address' => '12, King Fahd Rd, Olaya, Riyadh',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => 100, 'delivery_fee' => 15,
            'tax_amount' => 17.25, 'discount_amount' => 0, 'total_amount' => 132.25,
            'status' => Order::STATUS_PENDING_PAYMENT, 'payment_status' => Order::PAYMENT_PENDING,
        ]);
        $this->order->items()->create([
            'product_name' => 'Rose bouquet', 'product_image' => 'products/1.jpg', 'unit_price' => 50, 'quantity' => 2, 'subtotal' => 100,
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $this->sent[$url] = $request->data();

            return match (true) {
                str_contains($url, 'tabby.test/api/v2/checkout') => Http::response([
                    'id' => 'sess-1', 'status' => 'created', 'payment' => ['id' => 'pay-1'],
                    'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://tabby.test/hpp']]]],
                ]),
                str_contains($url, 'tabby.test/api/v2/payments/pay-1/captures') => Http::response(['status' => 'CLOSED']),
                str_contains($url, 'tabby.test/api/v2/payments/pay-1') => Http::response([
                    'id' => 'pay-1', 'status' => 'AUTHORIZED', 'amount' => '132.25', 'currency' => 'SAR',
                    'order' => ['reference_id' => 'GFT-TEST-1'], 'captures' => [],
                ]),
                str_contains($url, 'tamara.test/checkout/payment-types') => Http::response([['name' => 'PAY_BY_INSTALMENTS', 'supported_instalments' => [['instalments' => 3]]]]),
                str_contains($url, 'tamara.test/checkout') => Http::response(['checkout_url' => 'https://tamara.test/hpp', 'order_id' => 'tam-1', 'checkout_id' => 'chk-1']),
                str_contains($url, 'tamara.test/orders/tam-1/authorise') => Http::response(['status' => 'authorised']),
                str_contains($url, 'alrajhi.test') => Http::response([['status' => '1', 'result' => '777:https://alrajhi.test/hpp']]),
                default => Http::response([], 404),
            };
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function pay(string $gateway)
    {
        Sanctum::actingAs($this->customer);

        return $this->postJson("/api/v1/orders/{$this->order->id}/pay", ['gateway' => $gateway])->assertOk();
    }

    /**
     * Writes the gateway payloads when PAYLOAD_DUMP is set (to compare before / after a refactor).
     */
    protected function dump(string $name, mixed $data): void
    {
        if ($dir = getenv('PAYLOAD_DUMP')) {
            file_put_contents($dir.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_tabby_session_return_and_capture(): void
    {
        $this->pay('tabby')->assertJsonPath('data.redirect_url', 'https://tabby.test/hpp')->assertJsonPath('data.reference', 'pay-1');

        $payload = $this->sent['https://tabby.test/api/v2/checkout'];
        $this->dump('tabby', $payload);
        $this->assertSame('132.25', $payload['payment']['amount']);
        $this->assertSame('GFT-TEST-1', $payload['payment']['order']['reference_id']);
        $this->assertSame('+966500000001', $payload['payment']['buyer']['phone']);
        $this->assertSame(route('api.v1.payments.tabby.return', ['outcome' => 'success', 'order' => $this->order->id]), $payload['merchant_urls']['success']);
        $this->assertSame('pay-1', $this->order->fresh()->payment_data['tabby_payment_id']);

        $this->getJson("/api/v1/payments/tabby/success/{$this->order->id}?payment_id=pay-1")->assertOk()
            ->assertJsonPath('data.order_id', $this->order->id)
            ->assertJsonPath('data.is_paid', true);

        $this->assertSame(Order::PAYMENT_PAID, $this->order->fresh()->payment_status);
        $this->assertSame(['session_created', 'capture', 'captured'], $this->order->transactions()->orderBy('id')->pluck('event')->all());
    }

    public function test_tabby_webhook_finds_the_order_by_payment_id(): void
    {
        $this->pay('tabby');

        $this->postJson('/api/v1/webhooks/tabby', ['id' => 'pay-1', 'status' => 'authorized'], ['X-Tabby-Auth' => 'hook'])->assertOk()
            ->assertJsonPath('order_id', $this->order->id)
            ->assertJsonPath('status', Order::PAYMENT_PAID);
    }

    public function test_tamara_session_and_webhook(): void
    {
        $this->pay('tamara')->assertJsonPath('data.redirect_url', 'https://tamara.test/hpp')->assertJsonPath('data.reference', 'tam-1');

        $payload = $this->sent['https://tamara.test/checkout'];
        $this->dump('tamara', $payload);
        $this->assertSame((string) $this->order->id, $payload['order_reference_id']);
        $this->assertSame(132.25, $payload['total_amount']['amount']);
        $this->assertStringEndsWith('?order_id='.$this->order->id, $payload['merchant_url']['success']);

        $jwt = $this->jwt('nt');
        $this->postJson('/api/v1/webhooks/tamara?tamaraToken='.$jwt, [
            'event_type' => 'order_approved', 'order_reference_id' => (string) $this->order->id, 'order_id' => 'tam-1',
        ])->assertOk()->assertJsonPath('order_id', $this->order->id)->assertJsonPath('status', Order::PAYMENT_PAID);

        $this->assertSame('tamara', $this->order->fresh()->payment_method);
    }

    public function test_tamara_return_url(): void
    {
        $this->pay('tamara');

        $this->getJson("/api/v1/payments/tamara/success?order_id={$this->order->id}&orderId=tam-1&paymentStatus=approved")->assertOk()
            ->assertJsonPath('data.is_paid', true);
    }

    public function test_alrajhi_session_and_callback(): void
    {
        $this->pay('alrajhi')->assertJsonPath('data.redirect_url', 'https://alrajhi.test/hpp?PaymentID=777');

        $sent = $this->sent['https://alrajhi.test/pg/payment/hosted.htm'];
        $plain = json_decode((new AlRajhiEncryptionService)->decrypt($sent[0]['trandata']), true);
        $this->dump('alrajhi', array_replace_recursive($plain, [0 => ['trackId' => preg_replace('/_\d+$/', '_<time>', $plain[0]['trackId'])]]));
        $this->assertMatchesRegularExpression('/^'.$this->order->id.'_\d+$/', $plain[0]['trackId']);   // "{order id}_{time()}"
        $this->assertEquals(132.25, $plain[0]['amt']);

        $trandata = (new AlRajhiEncryptionService)->encrypt(urlencode(json_encode([['result' => 'CAPTURED', 'trackId' => $plain[0]['trackId']]])));
        $response = $this->postJson('/api/v1/payments/alrajhi/callback', ['trandata' => $trandata])->assertOk()
            ->assertJsonPath('data.order_id', $this->order->id);

        $this->assertSame(Order::PAYMENT_PAID, $this->order->fresh()->payment_status);
        $response->assertJsonPath('data.is_paid', true);

        $this->assertSame(1, $this->order->transactions()->where('status', PaymentTransaction::STATUS_CAPTURED)->count());
    }

    protected function jwt(string $secret): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode(['exp' => time() + 600]));

        return $head.'.'.$body.'.'.$b64(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }
}
