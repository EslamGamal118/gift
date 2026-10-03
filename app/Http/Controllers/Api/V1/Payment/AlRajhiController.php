<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\AlRajhiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AlRajhi (NeoPay) response / error URLs referenced by AlRajhiService
 * (`payment.callback` and `payment.failed`).
 */
class AlRajhiController extends Controller
{
    /**
     * POST|GET /api/v1/payments/alrajhi/callback  (trandata)
     */
    public function callback(Request $request, AlRajhiService $alrajhi): JsonResponse
    {
        $order = $alrajhi->callBack($request);

        if (! $order) {
            return ApiResponse::send(422, 'checkout.payment_failure');
        }

        return ApiResponse::success('checkout.payment_success', [
            'order_id' => $order->getKey(),
            'order_type' => $order->paymentType(),
            'order_number' => $order->paymentReference(),
            'payment_status' => $order->payment_status,
            'is_paid' => $order->isPaid(),
        ]);
    }

    /**
     * POST|GET /api/v1/payments/alrajhi/failed
     */
    public function failed(): JsonResponse
    {
        return ApiResponse::send(422, 'checkout.payment_failure');
    }
}
