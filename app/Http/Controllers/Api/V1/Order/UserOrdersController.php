<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\UserOrderIndexRequest;
use App\Http\Requests\Order\UserOrderShowRequest;
use App\Http\Resources\Checkout\OrderDetailsResource;
use App\Http\Resources\CustomOrder\CustomOrderResource;
use App\Http\Resources\Order\UserOrderResource;
use App\Models\CustomOrder;
use App\Services\UserOrderService;
use Illuminate\Http\JsonResponse;

/**
 * The customer's store orders ("My orders"). Custom orders have their own
 * list: UserCustomOrdersController. Actions stay on /orders/{id}.
 */
class UserOrdersController extends Controller
{
    use PaginatesResults;

    public function __construct(protected UserOrderService $orders) {}

    /**
     * GET /api/v1/user/orders?tab=active|history&search=
     */
    public function index(UserOrderIndexRequest $request): JsonResponse
    {
        $paginator = $this->orders->listStandard($request->user(), $request->tab(), $this->perPage($request), $request->search());

        return ApiResponse::success('messages.success', [
            'filter' => ['tab' => $request->tab(), 'search' => $request->search()],
        ] + $this->paginated($paginator, UserOrderResource::collection($paginator)));
    }

    /**
     * GET /api/v1/user/orders/{id}
     *
     * The same payload as /orders/{id}, prefixed with `order_type` and `tab`.
     * (`?type=custom` still returns a custom order, for older app versions.)
     */
    public function show(UserOrderShowRequest $request, int $id): JsonResponse
    {
        $type  = $request->type();
        $order = $this->orders->findForCustomer($request->user(), $type, $id);

        $details = $order instanceof CustomOrder ? new CustomOrderResource($order) : new OrderDetailsResource($order);

        return ApiResponse::success('messages.success', [
            'order_type' => $type,
            'tab'        => UserOrderService::tabFor($type, $order->status),
        ] + $details->resolve($request));
    }
}
