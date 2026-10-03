<?php

namespace App\Services;

use App\Interfaces\Payable;
use App\Models\Order;
use App\Models\ProviderSubscription;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tamara (buy now, pay later). Checkout sessions work for any Payable (store
 * order or custom order); the reference Tamara echoes back
 * (`order_reference_id`, return URLs' `order_id`) is Payable::paymentKey().
 */
class TamaraService
{
    private string $baseUrl;

    private ?string $apiToken;

    private ?string $notificationToken;

    private string $currency;

    private string $countryCode;

    private string $locale;

    public function __construct()
    {
        $this->baseUrl = rtrim(trim((string) config('services.tamara.base_url', 'https://api-sandbox.tamara.co')), '/');
        $this->apiToken = $this->normalizeBearerSecret(config('services.tamara.api_token'));
        $this->notificationToken = $this->normalizeBearerSecret(config('services.tamara.notification_token'));
        $this->currency = (string) config('services.tamara.currency', 'SAR');
        $this->countryCode = (string) config('services.tamara.country_code', 'SA');
        $this->locale = (string) config('services.tamara.locale', 'ar_SA');
    }

    public function isConfigured(): bool
    {
        if (!config('services.tamara.enabled', true)) {
            return false;
        }

        return $this->apiToken !== null && $this->apiToken !== '';
    }

    /**
     * Lightweight call to verify API token and base URL (same endpoint as checkout flow).
     *
     * @return array{ok: bool, http_status: int, error?: string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'http_status' => 0, 'error' => 'Tamara غير مُهيأة: أضف TAMARA_API_TOKEN أو عيّن TAMARA_ENABLED=false'];
        }

        try {
            $response = Http::timeout(20)
                ->withToken($this->apiToken)
                ->acceptJson()
                ->get($this->baseUrl . '/checkout/payment-types', [
                    'country' => $this->countryCode,
                    'currency' => $this->currency,
                    'order_value' => 100,
                    'phone' => '966500000000',
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'http_status' => -1, 'error' => $e->getMessage()];
        }

        $status = $response->status();
        if ($status === 200) {
            return ['ok' => true, 'http_status' => 200];
        }

        $msg = $response->json('message');
        if (!is_string($msg) || $msg === '') {
            $msg = $response->body();
        }

        return ['ok' => false, 'http_status' => $status, 'error' => $msg];
    }

    /**
     * @return array{success: bool, checkout_url?: string, tamara_order_id?: string, checkout_id?: string, message?: string}
     */
    public function createCheckoutSession(Payable $order): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'تمارا غير مهيأة (مفتاح API ناقص).'];
        }

        $order->loadMissing($order instanceof Order ? ['items.product', 'client'] : ['items', 'user']);

        $buyerPhone = $order->paymentBuyer()['phone'];
        $phoneInternational = $this->normalizePhoneInternational($buyerPhone);
        $phoneConsumer = $this->normalizeConsumerPhone($buyerPhone);

        $plan = $this->resolvePaymentPlan($order->paymentAmount(), $phoneInternational);

        $payload = $this->buildCheckoutPayload($order, $plan, $phoneConsumer);

        try {
            $response = Http::withToken($this->apiToken)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl . '/checkout', $payload);
        } catch (\Throwable $e) {
            Log::error('Tamara checkout HTTP exception', ['message' => $e->getMessage(), 'order' => $order->paymentKey()]);

            return ['success' => false, 'message' => 'تعذر الاتصال بتمارا. حاول لاحقاً.'];
        }

        if (!$response->successful()) {
            $status = $response->status();
            Log::warning('Tamara checkout rejected', [
                'order' => $order->paymentKey(),
                'status' => $status,
                'base_url' => $this->baseUrl,
                'api_token_length' => $this->apiToken !== null ? strlen($this->apiToken) : 0,
                'body' => $response->json() ?? $response->body(),
            ]);

            if ($status === 401) {
                $len = $this->apiToken !== null ? strlen($this->apiToken) : 0;
                Log::warning('Tamara 401: verify API token source and environment', [
                    'base_url' => $this->baseUrl,
                    'api_token_length' => $len,
                ]);

                return [
                    'success' => false,
                    'message' => 'تمارا رفضت المصادقة (401). انسخ **رمز API** من لوحة الشركاء → API Tokens (وليس Notification Token). Sandbox يستخدم https://api-sandbox.tamara.co فقط. بعد التعديل: php artisan config:clear ثم من السيرفر: php artisan tamara:test-connection',
                ];
            }

            $apiMsg = $this->tamaraResponseMessage($response);
            if ($status === 404 && stripos($apiMsg, 'merchant is not found') !== false) {
                Log::warning('Tamara merchant missing for this environment', [
                    'base_url' => $this->baseUrl,
                    'tamara_message' => $apiMsg,
                ]);

                return [
                    'success' => false,
                    'message' => 'تمارا (404): التاجر غير موجود في هذه البيئة. غالباً الرمز من **بيئة أخرى** (رمز الإنتاج مع Sandbox أو العكس)، أو حسابكم غير مفعّل في **Sandbox**. وحّد: Sandbox + https://api-sandbox.tamara.co + رمز API من وضع الاختبار في اللوحة، أو للإنتاج: https://api.tamara.co + رمز الإنتاج. ثم تواصل مع تمارا لتفعيل التاجر في البيئة المطلوبة.',
                ];
            }

            return [
                'success' => false,
                'message' => $apiMsg !== ''
                    ? ('تمارا: ' . $apiMsg)
                    : 'رفض تمارا إنشاء جلسة الدفع. راجع السجل أو بيانات الطلب.',
            ];
        }

        $data = $response->json();
        $checkoutUrl = $data['checkout_url'] ?? null;
        $tamaraOrderId = $data['order_id'] ?? null;
        $checkoutId = $data['checkout_id'] ?? null;

        if (!$checkoutUrl || !$tamaraOrderId) {
            Log::error('Tamara checkout missing fields', ['order' => $order->paymentKey(), 'data' => $data]);

            return ['success' => false, 'message' => 'استجابة تمارا غير مكتملة.'];
        }

        $order->mergePaymentData([
            'tamara_order_id' => $tamaraOrderId,
            'tamara_checkout_id' => $checkoutId,
            'tamara_checkout_created_at' => now()->toIso8601String(),
            'tamara_payment_type' => $plan['payment_type'],
            'tamara_instalments' => $plan['instalments'],
        ]);
        $order->save();

        return [
            'success' => true,
            'checkout_url' => $checkoutUrl,
            'tamara_order_id' => $tamaraOrderId,
            'checkout_id' => $checkoutId,
        ];
    }

    /**
     * Open a Tamara checkout for a seller's subscription.
     *
     * One digital line item -- the plan -- with the seller as consumer and no
     * shipping. The reference Tamara echoes back is `SUB-{id}`, so its
     * webhook can never be confused with a shop order. Nothing here writes to
     * the row; the caller stores what comes back.
     *
     * @return array{success: bool, message?: string, checkout_url?: string, tamara_order_id?: string, checkout_id?: string|null}
     */
    public function createSubscriptionCheckoutSession(ProviderSubscription $subscription): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'تمارا غير مهيأة (مفتاح API ناقص).'];
        }

        $subscription->loadMissing(['plan', 'provider']);
        $provider = $subscription->provider;
        $plan = $subscription->plan;

        $phoneInternational = $this->normalizePhoneInternational($provider?->phone);
        $phoneConsumer = $this->normalizeConsumerPhone($provider?->phone);
        $total = round((float) $subscription->grand_total, 2);
        $paymentPlan = $this->resolvePaymentPlan($total, $phoneInternational);
        [$firstName, $lastName] = $this->splitName($provider?->name);
        $reference = $subscription->gatewayReference();

        $returnUrl = fn (string $outcome): string => route('api.v1.subscriptions.payment.tamara.return', [
            'outcome'      => $outcome,
            'subscription' => $subscription->getKey(),
        ]);

        $payload = [
            'order_reference_id' => $reference,
            'order_number'       => $reference,
            'total_amount'       => ['amount' => $total, 'currency' => $this->currency],
            'shipping_amount'    => ['amount' => '0.00', 'currency' => $this->currency],
            'tax_amount'         => ['amount' => number_format((float) $subscription->tax_amount, 2, '.', ''), 'currency' => $this->currency],
            'description'        => $this->truncate(config('app.name') . ' — اشتراك ' . ($plan?->name ?? ''), 256),
            'country_code'       => $this->countryCode,
            'payment_type'       => $paymentPlan['payment_type'],
            'instalments'        => $paymentPlan['instalments'],
            'items'              => [[
                'reference_id' => (string) ($plan?->getKey() ?? 0),
                'type'         => 'Digital',
                'name'         => $this->truncate((string) ($plan?->name ?? 'Subscription'), 255),
                'sku'          => 'PLAN-' . ($plan?->getKey() ?? 0),
                'quantity'     => 1,
                'unit_price'   => ['amount' => $total, 'currency' => $this->currency],
                'total_amount' => ['amount' => number_format($total, 2, '.', ''), 'currency' => $this->currency],
            ]],
            'consumer' => array_filter([
                'email'        => $provider?->email,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'phone_number' => $phoneConsumer,
            ]),
            // Required by the API even for a digital good.
            'shipping_address' => [
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'line1'        => $this->truncate((string) ($provider?->address ?: '—'), 240) ?: '—',
                'city'         => $this->truncate((string) ($provider?->city ?: 'الرياض'), 120),
                'country_code' => $this->countryCode,
                'phone_number' => $phoneConsumer,
                'region'       => $this->truncate((string) ($provider?->city ?: '—'), 120),
            ],
            'merchant_url' => [
                'success' => $returnUrl('success'),
                'failure' => $returnUrl('failure'),
                'cancel'  => $returnUrl('cancel'),
            ],
            'platform' => (string) config('services.tamara.platform', config('app.name', 'Laravel')),
            'locale'   => $this->locale,
        ];

        try {
            $response = Http::withToken($this->apiToken)->acceptJson()->asJson()->post($this->baseUrl . '/checkout', $payload);
        } catch (\Throwable $e) {
            Log::error('Tamara subscription checkout HTTP exception', ['message' => $e->getMessage(), 'subscription_id' => $subscription->getKey()]);

            return ['success' => false, 'message' => 'تعذر الاتصال بتمارا. حاول لاحقاً.'];
        }

        if (!$response->successful()) {
            Log::error('Tamara subscription checkout rejected', [
                'subscription_id' => $subscription->getKey(),
                'status'          => $response->status(),
                'body'            => $response->json() ?? $response->body(),
            ]);

            return ['success' => false, 'message' => $this->tamaraResponseMessage($response)];
        }

        $data = $response->json();
        $checkoutUrl = $data['checkout_url'] ?? null;
        $tamaraOrderId = $data['order_id'] ?? null;

        if (!$checkoutUrl || !$tamaraOrderId) {
            Log::error('Tamara subscription checkout missing fields', ['subscription_id' => $subscription->getKey(), 'data' => $data]);

            return ['success' => false, 'message' => 'استجابة تمارا غير مكتملة.'];
        }

        return [
            'success'         => true,
            'checkout_url'    => (string) $checkoutUrl,
            'tamara_order_id' => (string) $tamaraOrderId,
            'checkout_id'     => isset($data['checkout_id']) ? (string) $data['checkout_id'] : null,
        ];
    }

    public function authoriseOrder(string $tamaraOrderId): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->acceptJson()
                ->post($this->baseUrl . '/orders/' . rawurlencode($tamaraOrderId) . '/authorise');
        } catch (\Throwable $e) {
            Log::error('Tamara authorise HTTP exception', ['message' => $e->getMessage(), 'tamara_order_id' => $tamaraOrderId]);

            return false;
        }

        if (!$response->successful()) {
            Log::warning('Tamara authorise failed', [
                'tamara_order_id' => $tamaraOrderId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Give a paid order's money back in full. Orders are only authorised here
     * (never captured), so an authorised order is cancelled
     * (POST /orders/{id}/cancel); one captured at Tamara meanwhile is refunded
     * (POST /payments/simplified-refund/{id}).
     *
     * @return array{success: bool, reference: ?string, payload: array<string, mixed>, message?: string}
     */
    public function refund(Payable $order, string $reference, string $comment): array
    {
        $tamaraOrderId = (string) data_get($order->payment_data, 'tamara_order_id', '');

        if (!$this->isConfigured() || $tamaraOrderId === '') {
            return ['success' => false, 'reference' => null, 'payload' => [], 'message' => 'no Tamara order id'];
        }

        $amount = ['amount' => round($order->paymentAmount(), 2), 'currency' => $this->currency];

        try {
            $cancel = Http::withToken($this->apiToken)->acceptJson()->asJson()
                ->post($this->baseUrl . '/orders/' . rawurlencode($tamaraOrderId) . '/cancel', ['total_amount' => $amount]);

            if ($cancel->successful()) {
                return ['success' => true, 'reference' => (string) ($cancel->json('cancel_id') ?? $tamaraOrderId), 'payload' => (array) ($cancel->json() ?? [])];
            }

            $refund = Http::withToken($this->apiToken)->acceptJson()->asJson()
                ->post($this->baseUrl . '/payments/simplified-refund/' . rawurlencode($tamaraOrderId), [
                    'total_amount' => $amount,
                    'comment' => $this->truncate($comment, 255),
                    'merchant_refund_id' => $reference,
                ]);
        } catch (\Throwable $e) {
            Log::error('Tamara refund HTTP exception', ['order' => $order->paymentKey(), 'tamara_order_id' => $tamaraOrderId, 'message' => $e->getMessage()]);

            return ['success' => false, 'reference' => $tamaraOrderId, 'payload' => [], 'message' => 'connection'];
        }

        if (!$refund->successful()) {
            Log::warning('Tamara refund rejected', [
                'order' => $order->paymentKey(),
                'cancel' => ['status' => $cancel->status(), 'body' => $cancel->json() ?? $cancel->body()],
                'refund' => ['status' => $refund->status(), 'body' => $refund->json() ?? $refund->body()],
            ]);

            return [
                'success' => false,
                'reference' => $tamaraOrderId,
                'payload' => ['cancel' => $cancel->json(), 'refund' => $refund->json()],
                'message' => $this->tamaraResponseMessage($refund) ?: 'HTTP ' . $refund->status(),
            ];
        }

        return ['success' => true, 'reference' => (string) ($refund->json('refund_id') ?? $tamaraOrderId), 'payload' => (array) ($refund->json() ?? [])];
    }

    /**
     * Verify HS256 JWT from Tamara webhook (tamaraToken query or Authorization Bearer).
     */
    public function verifyNotificationJwt(string $jwt): bool
    {
        if ($this->notificationToken === null || $this->notificationToken === '') {
            Log::error('Tamara notification token missing; cannot verify webhook');

            return false;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }

        [$h64, $p64, $s64] = $parts;
        $expected = hash_hmac('sha256', $h64 . '.' . $p64, $this->notificationToken, true);
        $signature = $this->base64UrlDecode($s64);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return false;
        }

        $payloadJson = $this->base64UrlDecode($p64);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return false;
        }

        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            return false;
        }

        return true;
    }

    /**
     * @return array{payment_type: string, instalments: int}
     */
    private function resolvePaymentPlan(float $orderTotal, string $phoneInternational): array
    {
        $defaultInstalments = (int) config('services.tamara.default_instalments', 3);

        $types = $this->fetchPaymentTypes($orderTotal, $phoneInternational);
        if ($types === null) {
            return ['payment_type' => 'PAY_BY_INSTALMENTS', 'instalments' => max(1, $defaultInstalments)];
        }

        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }
            if (($type['name'] ?? '') === 'PAY_NOW') {
                return ['payment_type' => 'PAY_NOW', 'instalments' => 1];
            }
        }

        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }
            if (($type['name'] ?? '') !== 'PAY_BY_INSTALMENTS') {
                continue;
            }
            $supported = $type['supported_instalments'] ?? [];
            if (is_array($supported) && $supported !== []) {
                $first = $supported[0];
                if (is_array($first) && isset($first['instalments'])) {
                    return [
                        'payment_type' => 'PAY_BY_INSTALMENTS',
                        'instalments' => max(1, (int) $first['instalments']),
                    ];
                }
            }

            return ['payment_type' => 'PAY_BY_INSTALMENTS', 'instalments' => max(1, $defaultInstalments)];
        }

        return ['payment_type' => 'PAY_BY_INSTALMENTS', 'instalments' => max(1, $defaultInstalments)];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetchPaymentTypes(float $orderTotal, string $phoneInternational): ?array
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->acceptJson()
                ->get($this->baseUrl . '/checkout/payment-types', [
                    'country' => $this->countryCode,
                    'currency' => $this->currency,
                    'order_value' => round($orderTotal, 2),
                    'phone' => $phoneInternational,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Tamara payment-types request failed', ['message' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('Tamara payment-types non-success', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        $json = $response->json();
        if (!is_array($json)) {
            return null;
        }

        return array_is_list($json) ? $json : null;
    }

    /**
     * @param array{payment_type: string, instalments: int} $plan
     */
    private function buildCheckoutPayload(Payable $order, array $plan, string $phoneConsumer): array
    {
        $buyer = $order->paymentBuyer();
        [$firstName, $lastName] = $this->splitName($buyer['name']);

        $shippingLine1 = $this->truncate((string) $buyer['address'], 240);
        $region = $this->truncate((string) ($buyer['region'] ?? ''), 120);

        $items = $order instanceof Order ? $this->storeOrderItems($order) : $this->payableItems($order);

        $shipping = round($order->paymentShippingAmount(), 2);
        $total = round($order->paymentAmount(), 2);

        $successUrl = route('tamara.return.success', [], true) . '?order_id=' . $order->paymentKey();
        $failureUrl = route('tamara.return.failure', [], true) . '?order_id=' . $order->paymentKey();
        $cancelUrl = route('tamara.return.cancel', [], true) . '?order_id=' . $order->paymentKey();

        return [
            'order_reference_id' => $order->paymentKey(),
            'order_number' => $order->paymentReference(),
            'total_amount' => [
                'amount' => $total,
                'currency' => $this->currency,
            ],
            'shipping_amount' => [
                'amount' => number_format($shipping, 2, '.', ''),
                'currency' => $this->currency,
            ],
            'tax_amount' => [
                'amount' => number_format($order->paymentTaxAmount(), 2, '.', ''),
                'currency' => $this->currency,
            ],
            'description' => $this->truncate(config('app.name') . ' — طلب ' . $order->paymentReference(), 256),
            'country_code' => $this->countryCode,
            'payment_type' => $plan['payment_type'],
            'instalments' => $plan['instalments'],
            'items' => $items,
            'consumer' => array_filter([
                'email' => $buyer['email'] ?: ($order->paymentCustomer()?->email),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone_number' => $phoneConsumer,
            ]),
            'shipping_address' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'line1' => $shippingLine1 !== '' ? $shippingLine1 : '—',
                'city' => $this->truncate((string) ($buyer['city'] ?: 'الرياض'), 120),
                'country_code' => $this->countryCode,
                'phone_number' => $phoneConsumer,
                'region' => $region !== '' ? $region : '—',
            ],
            'merchant_url' => [
                'success' => $successUrl,
                'failure' => $failureUrl,
                'cancel' => $cancelUrl,
            ],
            'platform' => (string) config('services.tamara.platform', config('app.name', 'Laravel')),
            'locale' => $this->locale,
        ];
    }

    /**
     * Store order lines with their catalogue product (SKU, image, page).
     *
     * @return list<array<string, mixed>>
     */
    private function storeOrderItems(Order $order): array
    {
        $items = [];
        foreach ($order->items as $line) {
            $product = $line->product;
            $sku = $product && $product->sku ? (string) $product->sku : 'SKU-' . $line->id;
            $imageUrl = null;
            if ($product && $product->main_image) {
                $imageUrl = asset('storage/' . ltrim($product->main_image, '/'));
            }
            $itemUrl = $product ? url('/products/'.$product->id) : url('/');

            $unit = round((float) $line->unit_price, 2);
            $lineTotal = round((float) $line->subtotal, 2);

            $items[] = array_filter([
                'reference_id' => (string) $line->id,
                'type' => 'Physical',
                'name' => $this->truncate((string) $line->product_name, 255),
                'sku' => $this->truncate($sku, 128),
                'quantity' => (int) $line->quantity,
                'unit_price' => [
                    'amount' => $unit,
                    'currency' => $this->currency,
                ],
                'total_amount' => [
                    'amount' => number_format($lineTotal, 2, '.', ''),
                    'currency' => $this->currency,
                ],
                'image_url' => $imageUrl,
                'item_url' => $itemUrl,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $items;
    }

    /**
     * Lines of any other payable (Payable::paymentLines()).
     *
     * @return list<array<string, mixed>>
     */
    private function payableItems(Payable $order): array
    {
        return array_map(fn (array $line) => array_filter([
            'reference_id' => $line['reference'],
            'type' => 'Physical',
            'name' => $this->truncate($line['name'], 255),
            'sku' => $this->truncate($line['reference'], 128),
            'quantity' => $line['quantity'],
            'unit_price' => [
                'amount' => round($line['unit_price'], 2),
                'currency' => $this->currency,
            ],
            'total_amount' => [
                'amount' => number_format($line['total'], 2, '.', ''),
                'currency' => $this->currency,
            ],
            'image_url' => $line['image_url'],
        ], fn ($v) => $v !== null && $v !== ''), $order->paymentLines());
    }

    private function splitName(?string $full): array
    {
        $full = trim((string) $full);
        if ($full === '') {
            return ['عميل', '-'];
        }
        $parts = preg_split('/\s+/u', $full, 2) ?: [];

        return [
            $this->truncate($parts[0] ?? 'عميل', 80),
            $this->truncate($parts[1] ?? '-', 80),
        ];
    }

    /**
     * Saudi mobile for consumer object (example: 566027755).
     */
    private function normalizeConsumerPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) >= 9 && str_starts_with($digits, '5')) {
            return substr($digits, 0, 9);
        }

        return $digits !== '' ? substr($digits, 0, 9) : '500000000';
    }

    /**
     * Full international number for payment-types (e.g. 9665xxxxxxxx).
     */
    private function normalizePhoneInternational(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '966')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) >= 9 && str_starts_with($digits, '5')) {
            return '966' . $digits;
        }

        return $digits !== '' ? $digits : '966500000000';
    }

    private function tamaraResponseMessage(Response $response): string
    {
        $msg = $response->json('message');
        if (is_string($msg) && $msg !== '') {
            return $msg;
        }

        return '';
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Trim whitespace / quotes and strip a leading "Bearer " so Http::withToken does not send "Bearer Bearer …".
     */
    private function normalizeBearerSecret(mixed $value): ?string
    {
        if ($value === null || !is_string($value)) {
            return null;
        }
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        if ((str_starts_with($s, '"') && str_ends_with($s, '"')) || (str_starts_with($s, "'") && str_ends_with($s, "'"))) {
            $s = substr($s, 1, -1);
            $s = trim($s);
        }
        if (stripos($s, 'Bearer ') === 0) {
            $s = trim(substr($s, 7));
        }

        return $s !== '' ? $s : null;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
