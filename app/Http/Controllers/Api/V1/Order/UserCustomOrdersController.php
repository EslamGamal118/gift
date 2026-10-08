<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\UserOrderIndexRequest;
use App\Http\Resources\CustomOrder\CustomOrderResource;
use App\Http\Resources\Order\UserOrderResource;
use App\Services\UserOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's custom (personal shopper) orders, in the same card / tab /
 * search format as their store orders (UserOrdersController). Actions stay on
 * /custom-orders/{id}.
 */
class UserCustomOrdersController extends Controller
{
    use PaginatesResults;

    public function __construct(protected UserOrderService $orders) {}

    /**
     * GET /api/v1/user/custom-orders?tab=active|history&search=
     */
    public function index(UserOrderIndexRequest $request): JsonResponse
    {
        $paginator = $this->orders->listCustom($request->user(), $request->tab(), $this->perPage($request), $request->search());

        return ApiResponse::success('messages.success', [
            'filter' => ['tab' => $request->tab(), 'search' => $request->search()],
            'tabs'   => $this->orders->tabs($request->user(), UserOrderService::TYPE_CUSTOM, $request->search()),
        ] + $this->paginated($paginator, UserOrderResource::collection($paginator)));
    }

    /**
     * GET /api/v1/user/custom-orders/{id}
     *
     * The same payload as /custom-orders/{id}, prefixed with `order_type` and `tab`.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->orders->findForCustomer($request->user(), UserOrderService::TYPE_CUSTOM, $id);

        return ApiResponse::success('messages.success', [
            'order_type' => UserOrderService::TYPE_CUSTOM,
            'tab'        => UserOrderService::tabFor(UserOrderService::TYPE_CUSTOM, $order->status),
        ] + (new CustomOrderResource($order))->resolve($request));
    }
}
