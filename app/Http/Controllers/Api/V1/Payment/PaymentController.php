<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\InitiatePaymentRequest;
use App\Http\Resources\Checkout\OrderResource;
use App\Models\Order;
use App\Services\PaymentInitiationService;
use App\Services\TabbyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starts a payment for a placed order with the chosen gateway and lets the
 * app poll the result after returning from the hosted payment page.
 */
class PaymentController extends Controller
{
    public function __construct(protected PaymentInitiationService $initiator) {}

    /**
     * POST /api/v1/orders/{order}/pay  { gateway: alrajhi | tamara | tabby }
     *
     * Returns the URL the app must open (hosted checkout page). For an order
     * split from a multi-store checkout the payment covers the whole checkout
     * (`checkout_id`, `amount`), never one store's share alone.
     */
    public function initiate(InitiatePaymentRequest $request, int $order): JsonResponse
    {
        $order = $this->find($request, $order);
        $gateway = $request->gateway();

        $result = $this->initiator->start($order, $gateway);

        if (! $result['success']) {
            $data = ['gateway' => $gateway, 'order_id' => $order->id]
                + (isset($result['rejection_reason']) ? ['rejection_reason' => $result['rejection_reason']] : []);

            return ApiResponse::send(422, $result['message'] ?? 'checkout.gateway_error', $data, ['gateway' => $gateway, 'detail' => '']);
        }

        return ApiResponse::success('checkout.payment_initiated', [
            'gateway' => $gateway,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'checkout_id' => $order->checkout_group_id,
            'amount' => $order->payableForPayment()->paymentAmount(),
            'redirect_url' => $result['redirect_url'],
            'reference' => $result['reference'] ?? null,
        ]);
    }

    /**
     * GET /api/v1/orders/{order}/payment-status
     *
     * Polled by the app after the hosted page redirects back. For Tabby the
     * payment is re-synced from the gateway so the app never waits on the webhook.
     */
    public function status(Request $request, int $order, TabbyService $tabby): JsonResponse
    {
        $order = $this->find($request, $order);
        $payable = $order->payableForPayment();   // the checkout, for a multi-store order

        $tabbyPaymentId = data_get($payable->payment_data, 'tabby_payment_id');
        if ($payable->payment_method === Order::METHOD_TABBY && ! $payable->isPaid() && $tabbyPaymentId) {
            $tabby->syncPayment($payable, (string) $tabbyPaymentId, 'poll');
            $order->refresh();
        }

        return ApiResponse::success('messages.success', [
            'order' => new OrderResource($order->load('storeProfile')),
            'payment_status' => $order->payment_status,
            'is_paid' => $order->isPaid(),
            'is_payable' => $order->isPayable(),
        ]);
    }

    protected function find(Request $request, int $id): Order
    {
        return $request->user()->orders()->findOrFail($id);
    }
}
