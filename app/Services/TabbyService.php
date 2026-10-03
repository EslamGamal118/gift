<?php

namespace App\Services;

use App\Interfaces\Payable;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tabby (pay in instalments) integration.
 *
 * Flow:
 *  1. createCheckoutSession()  -> POST /api/v2/checkout (public key) -> hosted checkout URL
 *  2. Customer pays on Tabby; Tabby redirects to our success / cancel / failure URL with ?payment_id=
 *  3. syncPayment()            -> GET /api/v2/payments/{id} (secret key) is the source of truth;
 *                                 AUTHORIZED payments are captured (auto_capture) and the order marked paid
 *  4. Webhook (POST /webhooks/tabby) repeats step 3 for asynchronous status changes.
 *
 * Nothing sent by the browser or the webhook body is trusted for the order state:
 * the payment is always re-fetched from Tabby and checked against the order.
 *
 * Works for any Payable (store order or custom order); "order" below means either.
 */
class TabbyService
{
    public const STATUS_NEW = 'NEW';

    public const STATUS_AUTHORIZED = 'AUTHORIZED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_EXPIRED = 'EXPIRED';

    /**
     * Rejection reasons Tabby requires merchants to surface with a specific message.
     */
    public const REJECTION_REASONS = ['not_available', 'order_amount_too_high', 'order_amount_too_low'];

    protected string $baseUrl;

    protected ?string $publicKey;

    protected ?string $secretKey;

    protected ?string $merchantCode;

    protected string $currency;

    public function __construct(protected PaymentService $payments)
    {
        $this->baseUrl = rtrim((string) config('services.tabby.base_url', 'https://api.tabby.ai'), '/');
        $this->publicKey = $this->secret(config('services.tabby.public_key'));
        $this->secretKey = $this->secret(config('services.tabby.secret_key'));
        $this->merchantCode = $this->secret(config('services.tabby.merchant_code'));
        $this->currency = (string) config('services.tabby.currency', 'SAR');
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.tabby.enabled', true)
            && $this->publicKey !== null
            && $this->secretKey !== null
            && $this->merchantCode !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Checkout session
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{success: bool, checkout_url?: string, session_id?: string, payment_id?: string, message?: string, rejection_reason?: string}
     */
    public function createCheckoutSession(Payable $order): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => __('checkout.gateway_unavailable', ['gateway' => 'Tabby'])];
        }

        $order->loadMissing(['items', 'user']);
        $payload = $this->checkoutPayload($order);

        try {
            $response = $this->client($this->publicKey)->post($this->baseUrl.'/api/v2/checkout', $payload);
        } catch (\Throwable $e) {
            Log::error('Tabby checkout HTTP exception', ['order' => $order->paymentKey(), 'message' => $e->getMessage()]);

            return ['success' => false, 'message' => __('checkout.gateway_error', ['detail' => 'connection'])];
        }

        if (! $response->successful()) {
            Log::warning('Tabby checkout rejected', [
                'order' => $order->paymentKey(),
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return ['success' => false, 'message' => $this->responseMessage($response)];
        }

        $data = $response->json() ?? [];

        // Tabby scored the buyer and declined (status "rejected") - show the mandated message.
        if (($data['status'] ?? null) !== 'created') {
            $reason = $this->rejectionReason($data);

            Log::info('Tabby session not created', ['order' => $order->paymentKey(), 'status' => $data['status'] ?? null, 'reason' => $reason]);

            $this->payments->recordTransaction($order, Order::METHOD_TABBY, 'session_rejected', PaymentTransaction::STATUS_FAILED, $data['id'] ?? null, $data);

            return [
                'success' => false,
                'rejection_reason' => $reason,
                'message' => __('checkout.tabby.rejection.'.$reason),
            ];
        }

        $sessionId = $data['id'] ?? null;
        $paymentId = data_get($data, 'payment.id');
        $checkoutUrl = data_get($data, 'configuration.available_products.installments.0.web_url');

        if (! $sessionId || ! $paymentId || ! $checkoutUrl) {
            Log::error('Tabby checkout response missing fields', ['order' => $order->paymentKey(), 'data' => $data]);

            return ['success' => false, 'message' => __('checkout.gateway_error', ['detail' => 'incomplete response'])];
        }

        $order->forceFill(['payment_method' => Order::METHOD_TABBY])
            ->mergePaymentData([
                'tabby_session_id' => $sessionId,
                'tabby_payment_id' => $paymentId,
                'tabby_created_at' => now()->toIso8601String(),
            ])
            ->save();

        $this->payments->recordTransaction($order, Order::METHOD_TABBY, 'session_created', PaymentTransaction::STATUS_INITIATED, $paymentId, [
            'session_id' => $sessionId,
        ]);

        return [
            'success' => true,
            'checkout_url' => (string) $checkoutUrl,
            'session_id' => (string) $sessionId,
            'payment_id' => (string) $paymentId,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Payment retrieval / capture
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>|null
     */
    public function retrievePayment(string $paymentId): ?array
    {
        try {
            $response = $this->client($this->secretKey)->get($this->baseUrl.'/api/v2/payments/'.rawurlencode($paymentId));
        } catch (\Throwable $e) {
            Log::error('Tabby retrieve payment HTTP exception', ['payment_id' => $paymentId, 'message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Tabby retrieve payment failed', ['payment_id' => $paymentId, 'status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Capture the full authorised amount (moves the payment to CLOSED).
     */
    public function capturePayment(Payable $order, string $paymentId): bool
    {
        try {
            $response = $this->client($this->secretKey)->post(
                $this->baseUrl.'/api/v2/payments/'.rawurlencode($paymentId).'/captures',
                ['amount' => $this->money($order->paymentAmount())],
            );
        } catch (\Throwable $e) {
            Log::error('Tabby capture HTTP exception', ['order' => $order->paymentKey(), 'payment_id' => $paymentId, 'message' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Tabby capture failed', ['order' => $order->paymentKey(), 'payment_id' => $paymentId, 'status' => $response->status(), 'body' => $response->body()]);

            $this->payments->recordTransaction($order, Order::METHOD_TABBY, 'capture_failed', PaymentTransaction::STATUS_AUTHORIZED, $paymentId, $response->json() ?? []);

            return false;
        }

        $this->payments->recordTransaction($order, Order::METHOD_TABBY, 'capture', PaymentTransaction::STATUS_CAPTURED, $paymentId, $response->json() ?? []);

        return true;
    }

    /**
     * Refund a captured (CLOSED) payment in full.
     * POST /api/v2/payments/{id}/refunds  { amount, reference_id (idempotency), reason }
     *
     * @return array{success: bool, reference: ?string, payload: array<string, mixed>, message?: string}
     */
    public function refund(Payable $order, string $reference, string $reason): array
    {
        $paymentId = (string) data_get($order->payment_data, 'tabby_payment_id', '');

        if ($paymentId === '') {
            return ['success' => false, 'reference' => null, 'payload' => [], 'message' => 'no Tabby payment id'];
        }

        try {
            $response = $this->client($this->secretKey)->post(
                $this->baseUrl.'/api/v2/payments/'.rawurlencode($paymentId).'/refunds',
                ['amount' => $this->money($order->paymentAmount()), 'reference_id' => $reference, 'reason' => mb_substr($reason, 0, 255)],
            );
        } catch (\Throwable $e) {
            Log::error('Tabby refund HTTP exception', ['order' => $order->paymentKey(), 'payment_id' => $paymentId, 'message' => $e->getMessage()]);

            return ['success' => false, 'reference' => $paymentId, 'payload' => [], 'message' => 'connection'];
        }

        $payload = $response->json() ?? [];

        if (! $response->successful()) {
            Log::warning('Tabby refund rejected', ['order' => $order->paymentKey(), 'payment_id' => $paymentId, 'status' => $response->status(), 'body' => $payload]);

            return ['success' => false, 'reference' => $paymentId, 'payload' => (array) $payload, 'message' => (string) ($payload['error'] ?? 'HTTP '.$response->status())];
        }

        return ['success' => true, 'reference' => (string) (data_get($payload, 'refunds.0.id') ?? $paymentId), 'payload' => (array) $payload];
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Sync order <- Tabby (used by return URLs and the webhook)
    |--------------------------------------------------------------------------
    */

    /**
     * Fetch the payment from Tabby, verify it belongs to the order and update the
     * order accordingly. Returns the resulting order payment status.
     *
     * @return array{status: string, tabby_status: string|null, order: Payable}
     */
    public function syncPayment(Payable $order, string $paymentId, string $source = 'return'): array
    {
        $storedId = data_get($order->payment_data, 'tabby_payment_id');

        // The id must be the one issued for this order at session creation.
        if ($storedId === null || ! hash_equals((string) $storedId, $paymentId)) {
            Log::warning('Tabby payment id mismatch', ['order' => $order->paymentKey(), 'given' => $paymentId, 'stored' => $storedId, 'source' => $source]);

            return ['status' => $order->payment_status, 'tabby_status' => null, 'order' => $order];
        }

        $payment = $this->retrievePayment($paymentId);

        if ($payment === null) {
            return ['status' => $order->payment_status, 'tabby_status' => null, 'order' => $order];
        }

        if (! $this->paymentMatchesOrder($payment, $order)) {
            Log::error('Tabby payment does not match order', ['order' => $order->paymentKey(), 'payment' => $payment]);

            return ['status' => $order->payment_status, 'tabby_status' => $payment['status'] ?? null, 'order' => $order];
        }

        $tabbyStatus = strtoupper((string) ($payment['status'] ?? ''));

        switch ($tabbyStatus) {
            case self::STATUS_AUTHORIZED:
                if ($this->isFullyCaptured($payment, $order) || $this->captureIfEnabled($order, $paymentId)) {
                    $this->payments->completeOrderPayment($order, ['source' => $source, 'payment' => $payment], Order::METHOD_TABBY, $paymentId);
                } else {
                    // Authorised but not captured yet (manual capture) - keep it visible.
                    $order->mergePaymentData(['tabby_status' => $tabbyStatus])->save();
                    $this->payments->recordTransaction($order, Order::METHOD_TABBY, 'authorized', PaymentTransaction::STATUS_AUTHORIZED, $paymentId, ['source' => $source]);
                }
                break;

            case self::STATUS_CLOSED:
                if ($this->isFullyCaptured($payment, $order)) {
                    $this->payments->completeOrderPayment($order, ['source' => $source, 'payment' => $payment], Order::METHOD_TABBY, $paymentId);
                } else {
                    // Closed without a full capture = voided / refunded at Tabby.
                    $this->payments->failOrderPayment($order, ['source' => $source, 'payment' => $payment], Order::METHOD_TABBY, $paymentId, 'closed_uncaptured');
                }
                break;

            case self::STATUS_REJECTED:
            case self::STATUS_EXPIRED:
                $this->payments->failOrderPayment($order, ['source' => $source, 'payment' => $payment], Order::METHOD_TABBY, $paymentId, strtolower($tabbyStatus));
                break;

            default: // NEW - customer has not completed the checkout
                $order->mergePaymentData(['tabby_status' => $tabbyStatus])->save();
        }

        $order->refresh();

        return ['status' => $order->payment_status, 'tabby_status' => $tabbyStatus, 'order' => $order];
    }

    /**
     * Customer left the hosted page through "cancel" - nothing to verify, just log it.
     */
    public function recordCancelled(Payable $order, ?string $paymentId): void
    {
        if ($order->isPaid()) {
            return;
        }

        $this->payments->failOrderPayment($order, ['source' => 'return'], Order::METHOD_TABBY, $paymentId, 'cancelled');
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Webhooks
    |--------------------------------------------------------------------------
    */

    /**
     * Tabby signs nothing; instead it sends the custom header registered with the
     * webhook. Compare it with our configured secret in constant time.
     */
    public function verifyWebhookRequest(Request $request): bool
    {
        $header = (string) config('services.tabby.webhook_header', 'X-Tabby-Auth');
        $secret = $this->secret(config('services.tabby.webhook_secret'));

        if ($secret === null) {
            Log::error('Tabby webhook secret missing; refusing webhook');

            return false;
        }

        $given = (string) $request->header($header, '');

        return $given !== '' && hash_equals($secret, $given);
    }

    /**
     * Locate the order for a webhook payload (a payment object) and sync it.
     */
    public function handleWebhook(array $payload): ?Payable
    {
        $paymentId = isset($payload['id']) ? (string) $payload['id'] : null;

        if ($paymentId === null || $paymentId === '') {
            return null;
        }

        $order = $this->findOrderByPaymentId($paymentId);

        if (! $order) {
            Log::warning('Tabby webhook for unknown payment', ['payment_id' => $paymentId]);

            return null;
        }

        $this->payments->recordTransaction($order, Order::METHOD_TABBY, 'webhook', PaymentTransaction::STATUS_INITIATED, $paymentId, $payload);

        return $this->syncPayment($order, $paymentId, 'webhook')['order'];
    }

    /**
     * Register this application's webhook URL with Tabby (run once per environment).
     *
     * @return array{success: bool, status: int, body: mixed}
     */
    public function registerWebhook(string $url): array
    {
        $response = $this->client($this->secretKey)
            ->withHeaders(['X-Tabby-Merchant-Code' => (string) $this->merchantCode])
            ->post($this->baseUrl.'/api/v1/webhooks', [
                'url' => $url,
                'is_test' => (bool) config('services.tabby.is_test', true),
                'header' => [
                    'title' => (string) config('services.tabby.webhook_header', 'X-Tabby-Auth'),
                    'value' => (string) config('services.tabby.webhook_secret'),
                ],
            ]);

        return ['success' => $response->successful(), 'status' => $response->status(), 'body' => $response->json() ?? $response->body()];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    public function findOrderByPaymentId(string $paymentId): ?Payable
    {
        $transaction = PaymentTransaction::query()
            ->gateway(Order::METHOD_TABBY)
            ->reference($paymentId)
            ->latest('id')
            ->first();

        return $transaction?->payable();
    }

    protected function captureIfEnabled(Payable $order, string $paymentId): bool
    {
        if (! config('services.tabby.auto_capture', true)) {
            return false;
        }

        return $this->capturePayment($order, $paymentId);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    protected function paymentMatchesOrder(array $payment, Payable $order): bool
    {
        $reference = (string) data_get($payment, 'order.reference_id', '');
        $amount = (string) ($payment['amount'] ?? '');
        $currency = strtoupper((string) ($payment['currency'] ?? ''));

        return hash_equals($order->paymentReference(), $reference)
            && $this->money($amount) === $this->money($order->paymentAmount())
            && $currency === strtoupper($order->paymentCurrency());
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    protected function isFullyCaptured(array $payment, Payable $order): bool
    {
        $captured = collect($payment['captures'] ?? [])->sum(fn ($c) => (float) ($c['amount'] ?? 0));

        return round($captured, 2) >= round($order->paymentAmount(), 2);
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkoutPayload(Payable $order): array
    {
        $user = $order->paymentCustomer();
        $buyer = $order->paymentBuyer();

        $items = ($order instanceof Order
            ? $order->items->map(function ($line) {
                return [
                    'title' => mb_substr((string) $line->product_name, 0, 255),
                    'description' => mb_substr((string) $line->product_name, 0, 255),
                    'quantity' => (int) $line->quantity,
                    'unit_price' => $this->money(((float) $line->subtotal) / max(1, (int) $line->quantity)),
                    'discount_amount' => '0.00',
                    'reference_id' => (string) ($line->product_id ?? $line->id),
                    'image_url' => $line->product_image ? asset('storage/'.ltrim($line->product_image, '/')) : null,
                    'category' => 'Gifts',
                ];
            })
            : collect($order->paymentLines())->map(fn (array $line) => [
                'title' => mb_substr($line['name'], 0, 255),
                'description' => mb_substr($line['name'], 0, 255),
                'quantity' => $line['quantity'],
                'unit_price' => $this->money($line['unit_price']),
                'discount_amount' => '0.00',
                'reference_id' => $line['reference'],
                'image_url' => $line['image_url'],
                'category' => $order instanceof CustomOrder ? 'Personal shopping' : 'Gifts',
            ])
        )->map(fn ($item) => array_filter($item, fn ($v) => $v !== null))->values()->all();

        $returnUrl = fn (string $outcome) => route('api.v1.payments.tabby.return', ['outcome' => $outcome, 'order' => $order->paymentKey()]);

        return [
            'payment' => [
                'amount' => $this->money($order->paymentAmount()),
                'currency' => $this->currency,
                'description' => mb_substr(config('app.name').' - '.$order->paymentReference(), 0, 255),
                'buyer' => array_filter([
                    'phone' => $this->e164($buyer['phone'] ?: $user?->phone),
                    'email' => $buyer['email'] ?: $user?->email,
                    'name' => $buyer['name'] ?: ($user?->name ?: 'Customer'),
                ]),
                'buyer_history' => [
                    'registered_since' => optional($user?->created_at)->toIso8601String() ?? now()->toIso8601String(),
                    'loyalty_level' => (int) Order::query()->forUser($order->user_id)->where('payment_status', Order::PAYMENT_PAID)->count(),
                ],
                'order' => [
                    'tax_amount' => $this->money($order->paymentTaxAmount()),
                    'shipping_amount' => $this->money($order->paymentShippingAmount()),
                    'discount_amount' => $this->money($order->paymentDiscountAmount()),
                    'updated_at' => $order->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    'reference_id' => $order->paymentReference(),
                    'items' => $items,
                ],
                'order_history' => $this->orderHistory($order),
                'shipping_address' => [
                    'city' => mb_substr((string) $buyer['city'], 0, 120),
                    'address' => mb_substr((string) $buyer['address'], 0, 255),
                    'zip' => '00000',
                ],
                'meta' => [
                    'order_id' => $order->paymentKey(),
                    'customer' => (string) $order->user_id,
                ],
            ],
            'lang' => (string) config('services.tabby.lang', app()->getLocale()),
            'merchant_code' => (string) $this->merchantCode,
            'merchant_urls' => [
                'success' => $returnUrl('success'),
                'cancel' => $returnUrl('cancel'),
                'failure' => $returnUrl('failure'),
            ],
        ];
    }

    /**
     * Previous paid store orders of the buyer help Tabby's scoring.
     *
     * @return list<array<string, mixed>>
     */
    protected function orderHistory(Payable $order): array
    {
        return Order::query()
            ->forUser($order->user_id)
            ->when($order instanceof Order, fn ($query) => $query->whereKeyNot($order->getKey()))
            ->where('payment_status', Order::PAYMENT_PAID)
            ->latest('paid_at')
            ->limit(10)
            ->get()
            ->map(fn (Order $past) => [
                'purchased_at' => $past->paid_at?->toIso8601String() ?? $past->created_at->toIso8601String(),
                'amount' => $this->money($past->total_amount),
                'payment_method' => $past->payment_method === Order::METHOD_TABBY ? 'installments' : 'card',
                'status' => $past->status === Order::STATUS_DELIVERED ? 'complete' : 'new',
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function rejectionReason(array $data): string
    {
        $reason = data_get($data, 'configuration.products.installments.rejection_reason')
            ?? data_get($data, 'rejection_reason_code')
            ?? data_get($data, 'rejection_reason');

        return in_array($reason, self::REJECTION_REASONS, true) ? $reason : 'not_available';
    }

    protected function client(?string $token): PendingRequest
    {
        return Http::timeout(30)
            ->withToken((string) $token)
            ->acceptJson()
            ->asJson();
    }

    protected function responseMessage(Response $response): string
    {
        $message = $response->json('error') ?? $response->json('message');

        return is_string($message) && $message !== ''
            ? __('checkout.gateway_error', ['detail' => $message])
            : __('checkout.gateway_error', ['detail' => 'HTTP '.$response->status()]);
    }

    protected function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * +9665XXXXXXXX
     */
    protected function e164(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }
        if (! str_starts_with($digits, '966')) {
            $digits = '966'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * Trim whitespace / quotes so a pasted key never carries stray characters.
     */
    protected function secret(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");

        return $value !== '' ? $value : null;
    }
}
