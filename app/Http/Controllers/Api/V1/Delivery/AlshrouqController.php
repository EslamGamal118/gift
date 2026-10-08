<?php

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Services\CustomOrderDeliveryService;
use App\Services\StoreOrderDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/webhooks/alshrouq?token={ALSHROUQ_WEBHOOK_SECRET}
 *
 * Alshrouq's status updates for the orders it delivers, custom orders and
 * store orders alike:
 *   { order_id, client_order_id, status_id, status, created_at,
 *     driver: { id, name, phone, status, tracking_url, location: { lat, lng } } }
 *
 * The order is found by Alshrouq's order id (stored when it was created),
 * else by `client_order_id`: a store order's number, or a custom order's id.
 * "Order delivered" ends a store order `delivered`, a custom order
 * `completed` (StoreOrderDeliveryService / CustomOrderDeliveryService).
 * Updates that do not apply
 * (unknown order or status, late or repeated update) are acknowledged with
 * 200 and logged, so Alshrouq does not retry them.
 */
class AlshrouqController extends Controller
{
    public function __construct(
        protected CustomOrderDeliveryService $delivery,
        protected StoreOrderDeliveryService $storeDelivery,
    ) {}

    public function webhook(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();
        $deliveryId = filled($payload['order_id'] ?? null) ? (string) $payload['order_id'] : null;
        $clientId = filled($payload['client_order_id'] ?? null) ? (string) $payload['client_order_id'] : null;
        $label = trim((string) ($payload['status'] ?? $payload['status_label'] ?? ''));

        if ((! $deliveryId && ! $clientId) || (blank($payload['status_id'] ?? null) && $label === '')) {
            return response()->json(['message' => 'The order and its status are required.'], 422);
        }

        $reason = filled($payload['cancellation_reason'] ?? $payload['reason'] ?? null) ? (string) ($payload['cancellation_reason'] ?? $payload['reason']) : null;
        $providerStatus = $label !== '' ? $label : (string) $payload['status_id'];

        if ($storeOrder = $this->findStoreOrder($deliveryId, $clientId)) {
            $status = StoreOrderDeliveryService::mapStatus($payload['status_id'] ?? null) ?? StoreOrderDeliveryService::mapStatus($label);

            if (! $status) {
                return $this->notApplied('unknown status', $payload);
            }

            $applied = $this->storeDelivery->apply($storeOrder, $status, $providerStatus, $reason);

            return response()->json(['received' => true, 'applied' => $applied, 'order_type' => 'standard', 'status' => $storeOrder->fresh()->status]);
        }

        $order = $this->findOrder($deliveryId, $clientId);
        $status = CustomOrderDeliveryService::mapStatus($payload['status_id'] ?? null) ?? CustomOrderDeliveryService::mapStatus($label);

        if (! $order || ! $status) {
            return $this->notApplied(! $order ? 'unknown order' : 'unknown status', $payload);
        }

        $applied = $this->delivery->apply(
            $order,
            $status,
            $providerStatus,
            $deliveryId,
            $reason,
            is_array($payload['driver'] ?? null) ? $payload['driver'] : null,
        );

        return response()->json(['received' => true, 'applied' => $applied, 'order_type' => 'custom', 'status' => $order->fresh()->status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function notApplied(string $why, array $payload): JsonResponse
    {
        Log::warning('Alshrouq webhook not applied: '.$why, ['payload' => $payload]);

        return response()->json(['received' => true, 'applied' => false]);
    }

    /**
     * A store order: by Alshrouq's id, else by its number (sent as
     * `client_order_id`) unless it is linked to another Alshrouq order.
     */
    protected function findStoreOrder(?string $deliveryId, ?string $clientId): ?Order
    {
        if ($deliveryId && ($order = Order::query()->where('delivery_reference', $deliveryId)->first())) {
            return $order;
        }

        if (! $clientId || ctype_digit($clientId)) {
            return null;
        }

        $order = Order::query()->where('order_number', $clientId)->first();

        return $order && (! $order->delivery_reference || $order->delivery_reference === $deliveryId) ? $order : null;
    }

    /**
     * The shared secret, in the webhook URL (`?token=`) or the configured header.
     */
    protected function authorized(Request $request): bool
    {
        $secret = (string) config('alshrouq.webhook_secret');
        $given = (string) ($request->query('token') ?: $request->header((string) config('alshrouq.webhook_header')));

        return $secret !== '' && hash_equals($secret, $given);
    }

    /**
     * By Alshrouq's id once it is known; the client id alone only matches an
     * order Alshrouq has not been linked to yet, or the same Alshrouq order.
     */
    protected function findOrder(?string $deliveryId, ?string $clientId): ?CustomOrder
    {
        if ($deliveryId && ($order = CustomOrder::query()->where('delivery_reference', $deliveryId)->first())) {
            return $order;
        }

        if (! $clientId || ! ctype_digit($clientId)) {
            return null;
        }

        $order = CustomOrder::query()->find((int) $clientId);

        return $order && (! $order->delivery_reference || $order->delivery_reference === $deliveryId) ? $order : null;
    }
}
