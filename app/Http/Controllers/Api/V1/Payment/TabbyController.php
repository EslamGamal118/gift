<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PayableResolver;
use App\Services\TabbyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tabby return URLs (browser redirects after the hosted checkout) and webhook.
 *
 * Both are unauthenticated by nature, so neither trusts its input: the return
 * handler only accepts the payment id issued for that order and the webhook
 * must carry the registered secret header; the order state is always taken
 * from Tabby's API (see TabbyService::syncPayment).
 *
 * `{order}` is the payable's key: a store order id, or `CO-{id}` for a custom order.
 */
class TabbyController extends Controller
{
    public function __construct(
        protected TabbyService $tabby,
        protected PayableResolver $payables,
    ) {}

    /**
     * GET /api/v1/payments/tabby/{outcome}/{order}?payment_id=
     *
     * outcome: success | cancel | failure (set by us in merchant_urls; Tabby appends payment_id).
     */
    public function handleReturn(Request $request, string $outcome, string $order): JsonResponse|RedirectResponse
    {
        $order = $this->payables->find($order) ?? abort(404);
        $paymentId = trim((string) $request->query('payment_id', ''));

        if ($outcome === 'cancel') {
            $this->tabby->recordCancelled($order, $paymentId ?: null);
        } elseif ($paymentId !== '') {
            // success and failure alike: Tabby decides, we only read the result.
            $this->tabby->syncPayment($order, $paymentId, 'return:'.$outcome);
        }

        $order->refresh();

        if ($appUrl = config('services.tabby.app_return_url')) {
            return redirect()->away($appUrl.(str_contains($appUrl, '?') ? '&' : '?').http_build_query([
                'gateway' => Order::METHOD_TABBY,
                'order' => $order->getKey(),
                'order_type' => $order->paymentType(),
                'outcome' => $outcome,
                'payment_status' => $order->payment_status,
            ]));
        }

        return ApiResponse::success($order->isPaid() ? 'checkout.payment_success' : 'checkout.payment_'.$this->normalizeOutcome($outcome), [
            'order_id' => $order->getKey(),
            'order_type' => $order->paymentType(),
            'order_number' => $order->paymentReference(),
            'outcome' => $outcome,
            'payment_status' => $order->payment_status,
            'is_paid' => $order->isPaid(),
        ]);
    }

    /**
     * POST /api/v1/webhooks/tabby
     *
     * Body is a Tabby payment object. Always answer 200 once authenticated so
     * Tabby does not keep retrying; problems are logged.
     */
    public function webhook(Request $request): JsonResponse
    {
        if (! $this->tabby->verifyWebhookRequest($request)) {
            Log::warning('Tabby webhook rejected: bad or missing auth header', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['message' => 'Empty payload'], 400);
        }

        $order = $this->tabby->handleWebhook($payload);

        return response()->json([
            'received' => true,
            'order_id' => $order?->getKey(),
            'order_type' => $order?->paymentType(),
            'status' => $order?->payment_status,
        ]);
    }

    protected function normalizeOutcome(string $outcome): string
    {
        return in_array($outcome, ['success', 'cancel', 'failure'], true) ? $outcome : 'failure';
    }
}
