<?php

namespace App\Http\Controllers\Api\V1\CustomOrder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomOrder\AssignShopperRequest;
use App\Http\Requests\CustomOrder\CancelCustomOrderRequest;
use App\Http\Requests\CustomOrder\ConfirmCustomOrderRequest;
use App\Http\Requests\CustomOrder\CustomOrderIndexRequest;
use App\Http\Requests\CustomOrder\RespondToAlternativesRequest;
use App\Http\Requests\CustomOrder\StoreCustomOrderRequest;
use App\Http\Resources\CustomOrder\CustomOrderResource;
use App\Models\CustomOrder;
use App\Services\CustomOrderService;
use Illuminate\Http\JsonResponse;

/**
 * The customer's custom ("personal shopper") orders.
 *
 * Flow: POST / (step 1: items, images, budget -> draft)
 *    -> POST {id}/assign-shopper (step 2: pick a shopper or choose bidding)
 *    -> POST {id}/confirm (step 3: address, delivery time, notes -> pending)
 *    -> GET / and GET {id} to track it.
 */
class CustomOrderController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected CustomOrderService $customOrders,
    ) {
    }

    /**
     * GET /api/v1/custom-orders?status=
     */
    public function index(CustomOrderIndexRequest $request): JsonResponse
    {
        $paginator = $this->customOrders->listForCustomer($request->user(), $request->status(), $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'filter' => ['status' => $request->status()],
        ] + $this->paginated($paginator, CustomOrderResource::collection($paginator)));
    }

    /**
     * POST /api/v1/custom-orders  (multipart)
     *
     * Creates the order as a draft with its items and reference images. A
     * `shopper_id` records the shopper choice (step 2) straight away; the
     * order is submitted to the shopper by the confirm step.
     */
    public function store(StoreCustomOrderRequest $request): JsonResponse
    {
        $order = $this->customOrders->create($request->user(), $request->orderData(), $request->itemImages());

        return ApiResponse::send(201, 'custom_orders.created', new CustomOrderResource($order));
    }

    /**
     * GET /api/v1/custom-orders/{customOrder}
     */
    public function show(CustomOrderIndexRequest $request, int $customOrder): JsonResponse
    {
        $order = $this->customOrders->findForCustomer($request->user(), $customOrder);

        return ApiResponse::success('messages.success', new CustomOrderResource($order));
    }

    /**
     * POST /api/v1/custom-orders/{customOrder}/assign-shopper
     *
     *   { "mode": "direct", "shopper_id": 12 }  -> choose that shopper
     *   { "mode": "bidding" }                   -> receive offers from shoppers instead
     *
     * On a draft this records the choice; the shopper(s) are notified once the
     * order is confirmed. A direct pick on an order already open for bids
     * assigns the shopper immediately.
     */
    public function assignShopper(AssignShopperRequest $request, int $customOrder): JsonResponse
    {
        $order = $this->find($request->user()->id, $customOrder);

        if ($request->mode() === CustomOrder::MODE_BIDDING) {
            $order = $this->customOrders->openBidding($order);

            return ApiResponse::success('custom_orders.bidding_opened', new CustomOrderResource($order));
        }

        $order = $this->customOrders->assignShopper($order, $request->shopperId());

        return ApiResponse::success('custom_orders.assigned', new CustomOrderResource($order));
    }

    /**
     * POST /api/v1/custom-orders/{customOrder}/confirm
     *
     * Step 3: delivery address (`address_id` or inline `address`), delivery
     * time (`delivery_at`, or `delivery_date` + `delivery_slot_id`) and
     * `confirmation_notes`. The draft becomes pending and the chosen shopper
     * is notified - or, in bidding mode, the round opens for offers.
     */
    public function confirm(ConfirmCustomOrderRequest $request, int $customOrder): JsonResponse
    {
        $order = $this->customOrders->confirm(
            $this->find($request->user()->id, $customOrder),
            $request->deliveryData(),
        );

        return ApiResponse::success(
            $order->isBidding() ? 'custom_orders.confirmed_bidding' : 'custom_orders.confirmed',
            new CustomOrderResource($order),
        );
    }

    /**
     * POST /api/v1/custom-orders/{customOrder}/cancel
     */
    /**
     * POST /api/v1/custom-orders/{customOrder}/alternatives/response
     * { alternatives: [{ id, is_approved }] }
     *
     * Saves the customer's answer to each suggested alternative. When none is
     * left pending the order resumes (accepted, or in progress if shopping had
     * started) and the shopper is notified. 409 once the order is no longer
     * being shopped; 422 for an alternative already answered or of another order.
     */
    public function respondToAlternatives(RespondToAlternativesRequest $request, int $customOrder): JsonResponse
    {
        $result = $this->customOrders->respondToAlternatives($request->order(), $request->decisions());

        return ApiResponse::success('custom_orders.alternatives_answered', [
            'approved' => $result['approved'],
            'rejected' => $result['rejected'],
            'resumed'  => $result['resumed'],
            'order'    => new CustomOrderResource($result['order']),
        ]);
    }

    public function cancel(CancelCustomOrderRequest $request, int $customOrder): JsonResponse
    {
        $order = $this->customOrders->cancel(
            $this->find($request->user()->id, $customOrder),
            CustomOrder::ACTOR_CUSTOMER,
            $request->validated('reason'),
        );

        return ApiResponse::success('custom_orders.cancelled', new CustomOrderResource($order));
    }

    protected function find(int $userId, int $id): CustomOrder
    {
        return CustomOrder::query()->forCustomer($userId)->findOrFail($id);
    }
}
