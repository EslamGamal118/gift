<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Orders\StoreOrderActionRequest;
use App\Http\Requests\Store\Orders\UpdateStoreOrderStatusRequest;
use App\Http\Resources\Store\OrderResource;
use App\Models\Order;
use App\Services\StoreOrderService;
use Illuminate\Http\JsonResponse;

/**
 * Status transitions a store can perform on its orders. Each action runs
 * through the OrderStateMachine (409 when not allowed from the current
 * status) and notifies the customer in their own language.
 */
class StoreOrderStatusController extends Controller
{
    /**
     * Requested status => success message.
     */
    protected const STATUS_MESSAGES = [
        'accepted' => 'orders.accepted',
        'preparing' => 'orders.processing',
        'ready_for_pickup' => 'orders.ready',
        'send_to_captain' => 'orders.dispatched',
        'out_for_delivery' => 'orders.dispatched',
        'completed' => 'orders.delivered',
        'cancelled' => 'orders.cancelled',
    ];

    public function __construct(protected StoreOrderService $orders) {}

    /**
     * POST /api/v1/store/orders/{order}/status { status, reason? }
     *
     * status: accepted | preparing | ready_for_pickup | send_to_captain | completed | cancelled
     * (`reason` is required when cancelling). Unpaid orders are 404, a status
     * out of flow is 422, and the customer is notified of the new status.
     * `send_to_captain` broadcasts the order to every active captain.
     */
    public function update(UpdateStoreOrderStatusRequest $request, int $order): JsonResponse
    {
        $status = $request->targetStatus();

        return $this->respond(self::STATUS_MESSAGES[$status], $this->orders->updateStatus(
            $request->order(), $request->user(), $status, $request->reason(),
        ));
    }

    /**
     * POST /api/v1/store/orders/{order}/start-preparing   accepted -> processing
     */
    public function startPreparing(StoreOrderActionRequest $request, int $order): JsonResponse
    {
        return $this->respond('orders.processing', $this->orders->startPreparing($request->order(), $request->user()));
    }

    /**
     * POST /api/v1/store/orders/{order}/ready   processing -> ready
     */
    public function ready(StoreOrderActionRequest $request, int $order): JsonResponse
    {
        return $this->respond('orders.ready', $this->orders->markReady($request->order(), $request->user()));
    }

    /**
     * POST /api/v1/store/orders/{order}/dispatch-captain   ready -> out_for_delivery, broadcast to captains
     */
    public function dispatchCaptain(StoreOrderActionRequest $request, int $order): JsonResponse
    {
        return $this->respond('orders.dispatched', $this->orders->dispatchToCaptains($request->order(), $request->user()));
    }

    protected function respond(string $messageKey, Order $order): JsonResponse
    {
        return ApiResponse::success($messageKey, new OrderResource($this->orders->details($order)));
    }
}
