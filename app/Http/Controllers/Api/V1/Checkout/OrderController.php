<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CancelOrderRequest;
use App\Http\Resources\Checkout\OrderCardResource;
use App\Http\Resources\Checkout\OrderDetailsResource;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's orders.
 */
class OrderController extends Controller
{
    use PaginatesResults;

    public function __construct(protected PaymentService $payments) {}

    /**
     * GET /api/v1/orders?status=  ("My orders" cards)
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()->orders()
            ->with(OrderCardResource::RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate($this->perPage($request));

        return ApiResponse::success('messages.success', $this->paginated($paginator, OrderCardResource::collection($paginator)));
    }

    /**
     * GET /api/v1/orders/{order}  ("Order details" screen)
     */
    public function show(Request $request, int $order): JsonResponse
    {
        $order = $this->find($request, $order)->load(OrderDetailsResource::RELATIONS);

        return ApiResponse::success('messages.success', new OrderDetailsResource($order));
    }

    /**
     * POST /api/v1/orders/{order}/cancel  (unpaid orders only)
     */
    public function cancel(CancelOrderRequest $request, int $order): JsonResponse
    {
        $order = $this->payments->cancelOrder($this->find($request, $order), $request->validated('reason'));

        return ApiResponse::success('checkout.order_cancelled', new OrderDetailsResource($order->load(OrderDetailsResource::RELATIONS)));
    }

    protected function find(Request $request, int $id): Order
    {
        return $request->user()->orders()->findOrFail($id);
    }
}
