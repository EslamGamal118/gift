<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\PayCustomOrderRequest;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Services\CustomOrderCheckoutService;
use App\Services\TabbyService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying a custom (personal shopper) order with the existing gateways
 * (AlRajhi / Tamara / Tabby). The amount is always computed on the server.
 */
class CustomOrderPaymentController extends Controller
{
    public function __construct(protected CustomOrderCheckoutService $checkout) {}

    /**
     * POST /api/v1/checkout/pay  { order_id, payment_method, delivery_address_id? }
     *
     * Prices the order (items + shopper fees + delivery + VAT) and returns the
     * hosted payment page to open. 409 before the shopper's invoice or once
     * paid / cancelled; 422 when the gateway declines (Tabby rejection reason included).
     */
    public function pay(PayCustomOrderRequest $request): JsonResponse
    {
        $gateway = $request->gateway();
        ['order' => $order, 'result' => $result] = $this->checkout->pay(
            $request->user(), $request->orderId(), $gateway, $request->deliveryAddressId(),
        );

        if (! $result['success']) {
            $data = ['gateway' => $gateway, 'order_id' => $order->id, 'order_type' => $order->paymentType()]
                + (isset($result['rejection_reason']) ? ['rejection_reason' => $result['rejection_reason']] : []);

            return ApiResponse::send(422, $result['message'] ?? 'checkout.gateway_error', $data, ['gateway' => $gateway, 'detail' => '']);
        }

        return ApiResponse::success('checkout.payment_initiated', [
            'gateway'      => $gateway,
            'order_id'     => $order->id,
            'order_type'   => $order->paymentType(),
            'order_number' => $order->order_number,
            'redirect_url' => $result['redirect_url'],
            'reference'    => $result['reference'] ?? null,
            'pricing'      => $this->pricing($order),
        ]);
    }

    /**
     * GET /api/v1/custom-orders/{customOrder}/payment-status
     *
     * Polled by the app after the hosted page redirects back. For Tabby the
     * payment is re-synced from the gateway so the app never waits on the webhook.
     */
    public function status(Request $request, int $customOrder, TabbyService $tabby): JsonResponse
    {
        /** @var CustomOrder $order */
        $order = CustomOrder::query()->forCustomer($request->user()->id)->findOrFail($customOrder);

        $tabbyPaymentId = data_get($order->payment_data, 'tabby_payment_id');
        if ($order->payment_method === Order::METHOD_TABBY && ! $order->isPaid() && $tabbyPaymentId) {
            $order = $tabby->syncPayment($order, (string) $tabbyPaymentId, 'poll')['order'];
        }

        return ApiResponse::success('messages.success', [
            'order_id'       => $order->id,
            'order_type'     => $order->paymentType(),
            'order_number'   => $order->order_number,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'paid_at'        => $order->paid_at?->toIso8601String(),
            'is_paid'        => $order->isPaid(),
            'is_payable'     => $order->isPayable(),
            'pricing'        => $order->total_amount !== null ? $this->pricing($order) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pricing(CustomOrder $order): array
    {
        $money = fn ($amount) => Money::format($amount, $order->currency);

        return [
            'subtotal'     => $money((float) $order->final_amount - (float) $order->shopper_fees),
            'shopper_fees' => $money($order->shopper_fees),
            'delivery_fee' => $money($order->delivery_fee),
            'tax'          => $money($order->tax_amount),
            'tax_rate'     => (float) config('checkout.tax.rate', 0),
            'total'        => $money($order->total_amount),
        ];
    }
}
