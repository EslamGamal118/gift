<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Orders\StoreOrderActionRequest;
use App\Http\Requests\Store\Orders\StoreOrderIndexRequest;
use App\Http\Resources\Store\OrderCardResource;
use App\Http\Resources\Store\OrderResource;
use App\Services\StoreOrderService;
use Illuminate\Http\JsonResponse;

/**
 * Order list and details for the merchant app. Ownership is enforced by the
 * form requests; unpaid orders are never shown.
 */
class StoreOrderController extends Controller
{
    use PaginatesResults;

    public function __construct(protected StoreOrderService $orders) {}

    /**
     * GET /api/v1/store/orders?status=pending,accepted&search=&date_range=today&page=
     *
     * Paid orders only: an order exists (and reaches its store) once the
     * customer's payment is confirmed by the gateway.
     */
    public function index(StoreOrderIndexRequest $request): JsonResponse
    {
        $store = $request->user();
        $filters = $request->filters();

        $paginator = $this->orders->paginate($store, $filters, $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'counts' => $this->orders->counts($store, $filters),
            'filters' => (object) $filters,
        ] + $this->paginated($paginator, OrderCardResource::collection($paginator)));
    }

    /**
     * GET /api/v1/store/orders/{order}
     */
    public function show(StoreOrderActionRequest $request, int $order): JsonResponse
    {
        return ApiResponse::success('messages.success', new OrderResource($this->orders->details($request->order())));
    }
}
