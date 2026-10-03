<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\InitiatePaymentRequest;
use App\Http\Resources\Checkout\CheckoutGroupResource;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Services\PaymentInitiationService;
use App\Services\TabbyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A placed (possibly multi-store) checkout: one payment for all the per-store
 * orders it was split into.
 */
class CheckoutPaymentController extends Controller
{
    public function __construct(protected PaymentInitiationService $initiator) {}

    /**
     * GET /api/v1/checkouts/{checkout}
     */
    public function show(Request $request, int $checkout): JsonResponse
    {
        return ApiResponse::success('messages.success', new CheckoutGroupResource($this->find($request, $checkout)->load(['orders.items.addons', 'orders.storeProfile'])));
    }

    /**
     * POST /api/v1/checkouts/{checkout}/pay  { gateway: alrajhi | tamara | tabby }
     *
     * Returns the URL the app must open (hosted checkout page) for the full amount.
     */
    public function pay(InitiatePaymentRequest $request, int $checkout): JsonResponse
    {
        $checkout = $this->find($request, $checkout);
        $gateway = $request->gateway();

        $result = $this->initiator->start($checkout, $gateway);

        if (! $result['success']) {
            $data = ['gateway' => $gateway, 'checkout_id' => $checkout->id]
                + (isset($result['rejection_reason']) ? ['rejection_reason' => $result['rejection_reason']] : []);

            return ApiResponse::send(422, $result['message'] ?? 'checkout.gateway_error', $data, ['gateway' => $gateway, 'detail' => '']);
        }

        return ApiResponse::success('checkout.payment_initiated', [
            'gateway' => $gateway,
            'checkout_id' => $checkout->id,
            'reference' => $checkout->reference,
            'amount' => (float) $checkout->total_amount,
            'redirect_url' => $result['redirect_url'],
            'gateway_reference' => $result['reference'] ?? null,
        ]);
    }

    /**
     * GET /api/v1/checkouts/{checkout}/payment-status
     *
     * Polled by the app after the hosted page redirects back. For Tabby the
     * payment is re-synced from the gateway so the app never waits on the webhook.
     */
    public function status(Request $request, int $checkout, TabbyService $tabby): JsonResponse
    {
        $checkout = $this->find($request, $checkout);

        $tabbyPaymentId = data_get($checkout->payment_data, 'tabby_payment_id');
        if ($checkout->payment_method === Order::METHOD_TABBY && ! $checkout->isPaid() && $tabbyPaymentId) {
            $checkout = $tabby->syncPayment($checkout, (string) $tabbyPaymentId, 'poll')['order'];
        }

        $checkout->refresh()->load('orders.storeProfile');

        return ApiResponse::success('messages.success', [
            'checkout' => new CheckoutGroupResource($checkout),
            'payment_status' => $checkout->payment_status,
            'is_paid' => $checkout->isPaid(),
            'is_payable' => $checkout->isPayable(),
        ]);
    }

    protected function find(Request $request, int $id): CheckoutGroup
    {
        return CheckoutGroup::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
