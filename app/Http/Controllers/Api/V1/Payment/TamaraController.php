<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Interfaces\Payable;
use App\Models\Order;
use App\Services\PaymentService;
use App\Services\Payments\PayableResolver;
use App\Services\TamaraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tamara return URLs and notification webhook (routes referenced by TamaraService).
 * The Tamara order id must match the one stored at session creation; approved
 * orders are authorised at Tamara before the local order is marked paid.
 *
 * `order_id` / `order_reference_id` is the payable's key: a store order id, or
 * `CO-{id}` for a custom order.
 */
class TamaraController extends Controller
{
    public function __construct(
        protected TamaraService $tamara,
        protected PaymentService $payments,
        protected PayableResolver $payables,
    ) {}

    /**
     * GET /api/v1/payments/tamara/{outcome}?order_id=&orderId=&paymentStatus=
     */
    public function handleReturn(Request $request, string $outcome): JsonResponse
    {
        $order = $this->payables->find((string) $request->query('order_id')) ?? abort(404);
        $tamaraOrderId = (string) $request->query('orderId', '');
        $status = strtolower((string) $request->query('paymentStatus', ''));

        if ($outcome === 'success' && $status === 'approved') {
            $this->approve($order, $tamaraOrderId, 'return');
        } elseif ($outcome !== 'success') {
            $this->payments->failOrderPayment($order, $request->query(), Order::METHOD_TAMARA, $tamaraOrderId ?: null, $outcome === 'cancel' ? 'cancelled' : 'failed');
        }

        $order->refresh();

        return ApiResponse::success($order->isPaid() ? 'checkout.payment_success' : 'checkout.payment_'.$outcome, [
            'order_id' => $order->getKey(),
            'order_type' => $order->paymentType(),
            'order_number' => $order->paymentReference(),
            'payment_status' => $order->payment_status,
            'is_paid' => $order->isPaid(),
        ]);
    }

    /**
     * POST /api/v1/webhooks/tamara?tamaraToken=
     */
    public function webhook(Request $request): JsonResponse
    {
        $jwt = (string) ($request->query('tamaraToken') ?: $request->bearerToken());

        if ($jwt === '' || ! $this->tamara->verifyNotificationJwt($jwt)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();
        $order = $this->payables->find((string) ($payload['order_reference_id'] ?? ''));

        if (! $order) {
            Log::warning('Tamara webhook for unknown order', ['payload' => $payload]);

            return response()->json(['received' => true]);
        }

        $tamaraOrderId = (string) ($payload['order_id'] ?? '');

        switch ($payload['event_type'] ?? '') {
            case 'order_approved':
                $this->approve($order, $tamaraOrderId, 'webhook');
                break;
            case 'order_declined':
            case 'order_expired':
            case 'order_canceled':
                $this->payments->failOrderPayment($order, $payload, Order::METHOD_TAMARA, $tamaraOrderId ?: null, str_replace('order_', '', $payload['event_type']));
                break;
        }

        return response()->json(['received' => true, 'order_id' => $order->getKey(), 'order_type' => $order->paymentType(), 'status' => $order->fresh()->payment_status]);
    }

    protected function approve(Payable $order, string $tamaraOrderId, string $source): void
    {
        $stored = (string) data_get($order->payment_data, 'tamara_order_id', '');

        if ($tamaraOrderId === '' || $stored === '' || ! hash_equals($stored, $tamaraOrderId)) {
            Log::warning('Tamara order id mismatch', ['order' => $order->paymentKey(), 'given' => $tamaraOrderId, 'source' => $source]);

            return;
        }

        if ($order->isPaid()) {
            return;
        }

        if ($this->tamara->authoriseOrder($tamaraOrderId)) {
            $this->payments->completeOrderPayment($order, ['source' => $source], Order::METHOD_TAMARA, $tamaraOrderId);
        }
    }
}
