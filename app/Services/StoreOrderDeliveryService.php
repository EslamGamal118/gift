<?php

namespace App\Services;

use App\Exceptions\DeliveryException;
use App\Exceptions\OrderStateException;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\AlshrouqClient;
use App\Services\Delivery\AlshrouqException;
use App\Support\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivery of store orders by the delivery company (Alshrouq):
 *
 *   dispatch()  the store sends a `ready` order to the captain
 *               (POST /store/orders/{id}/status { status: send_to_captain }):
 *               the order is created at Alshrouq (pickup = the store's main
 *               branch, drop-off = the customer's address) and takes its
 *               status, usually `order_created`. If Alshrouq refuses it, nothing
 *               changes (422) and the store can try again.
 *   apply()     every Alshrouq status update (webhook) becomes the order's
 *               `status` (Order::canMoveDeliveryTo()); "Order delivered" ends it
 *               `delivered` like any other order. The customer is told each time.
 *
 * Every change is recorded in order_status_histories.
 */
class StoreOrderDeliveryService
{
    public function __construct(
        protected AlshrouqClient $alshrouq,
        protected NotificationService $notifications,
    ) {}

    /**
     * Whether `send_to_captain` goes to Alshrouq (config `alshrouq.store_orders`
     * and a token); else the order is broadcast to the app's own captains.
     */
    public function enabled(): bool
    {
        return (bool) config('alshrouq.store_orders', true) && $this->alshrouq->isConfigured();
    }

    /**
     * Our order status for an Alshrouq status (id or name): its custom order
     * status, with "delivered" (`completed` there) as a store order's `delivered`.
     */
    public static function mapStatus(int|string|null $providerStatus): ?string
    {
        $status = AlshrouqClient::mapStatus($providerStatus);

        return $status === CustomOrder::STATUS_COMPLETED ? Order::STATUS_DELIVERED : $status;
    }

    /*
    |--------------------------------------------------------------------------
    | Dispatch
    |--------------------------------------------------------------------------
    */

    /**
     * Hand a `ready` order to Alshrouq. The order, its status and history only
     * change once Alshrouq has accepted it; on any failure nothing is saved.
     *
     * @throws OrderStateException  not `ready` (409)
     * @throws DeliveryException  no pickup / drop-off location, Alshrouq refused or unreachable (422)
     */
    public function dispatch(Order $order, User $store): Order
    {
        $order = DB::transaction(function () use ($order, $store) {
            /** @var Order $order */
            $order = Order::query()->with(['storeProfile.mainBranch', 'user', 'items'])->lockForUpdate()->findOrFail($order->getKey());
            $from = $order->status;

            if ($from !== Order::STATUS_READY || $order->delivery_reference) {
                throw OrderStateException::invalidTransition(OrderStateMachine::ACTION_DISPATCH, $from);
            }

            try {
                $created = $this->alshrouq->createOrder($this->payload($order));
            } catch (AlshrouqException $e) {
                Log::warning('Alshrouq dispatch of a store order failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

                throw DeliveryException::failed($e->getMessage());
            }

            $label = (string) ($created['status_label'] ?? $created['status_id'] ?? '');
            $status = self::mapStatus($created['status_id'] ?? null) ?? self::mapStatus($label);

            $order->forceFill([
                'delivery_reference' => (string) $created['order_id'],
                'delivery_status' => mb_substr($label, 0, 64) ?: null,
                'delivery_updated_at' => now(),
                // Alshrouq usually answers "Order created"; never anything past pickup here
                'status' => in_array($status, Order::DELIVERY_STATUSES, true) ? $status : Order::STATUS_ORDER_CREATED,
            ])->save();

            $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $order->status,
                'actor_type' => Order::ACTOR_STORE,
                'actor_id' => $store->getKey(),
                'reason' => 'Alshrouq #'.$order->delivery_reference,
            ]);

            return $order;
        });

        $this->notify($order, Order::STATUS_READY);

        return $order;
    }

    /**
     * The create-order request: no Alshrouq branch, the pickup point is the
     * store's main branch, given by its coordinates.
     *
     * @return array<string, mixed>
     *
     * @throws DeliveryException
     */
    public function payload(Order $order): array
    {
        $branch = $order->storeProfile?->mainBranch;

        if (! $branch?->latitude || ! $branch?->longitude) {
            throw DeliveryException::storeLocationRequired();
        }

        if (! $order->shipping_latitude || ! $order->shipping_longitude) {
            throw DeliveryException::customerLocationRequired();
        }

        $items = $order->items->map(fn ($item) => $item->quantity.'x '.$item->product_name)->implode(', ');

        return [
            'branch_lat' => (float) $branch->latitude,
            'branch_lng' => (float) $branch->longitude,
            'branch_id' => null,
            'client_order_id' => $order->order_number,
            'value' => round((float) $order->total_amount, 2),
            'payment_type' => (int) config('alshrouq.payment_type', 3),
            'preparation_time' => max(0, (int) config('alshrouq.preparation_time', 0)),
            'customer_lat' => (float) $order->shipping_latitude,
            'customer_lng' => (float) $order->shipping_longitude,
            'customer_address' => (string) $order->shipping_address,
            'customer_phone' => AlshrouqClient::localPhone((string) ($order->shipping_phone ?: $order->user?->phone)),
            'customer_name' => (string) ($order->shipping_name ?: $order->user?->name),
            'details' => mb_substr(trim($order->order_number.' - '.$items), 0, 500),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Status updates (webhook)
    |--------------------------------------------------------------------------
    */

    /**
     * Move the order to `$status` (one of ours, see mapStatus()). Returns false
     * (status unchanged) when the update does not apply: not with Alshrouq,
     * already finished, a repeated or late update. Alshrouq's raw status is
     * kept either way.
     */
    public function apply(Order $order, string $status, string $providerStatus, ?string $reason = null): bool
    {
        [$moved, $previous, $order] = DB::transaction(function () use ($order, $status, $providerStatus, $reason) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $previous = $order->status;
            $moved = $order->canMoveDeliveryTo($status);

            $order->forceFill([
                'delivery_status' => mb_substr($providerStatus, 0, 64),
                'delivery_updated_at' => now(),
            ]);

            if ($moved) {
                $order->forceFill(['status' => $status] + match ($status) {
                    Order::STATUS_ORDER_PICKED_UP => $order->dispatched_at ? [] : ['dispatched_at' => now()],
                    Order::STATUS_ARRIVED_TO_DROPOFF => $order->dispatched_at ? [] : ['dispatched_at' => now()],
                    Order::STATUS_DELIVERED => ['delivered_at' => now()],
                    Order::STATUS_CANCELLED => [
                        'cancelled_at' => now(),
                        'cancelled_by' => Order::ACTOR_DELIVERY,
                        'cancellation_reason' => $reason ?: $order->cancellation_reason,
                    ],
                    default => [],
                });

                $order->statusHistories()->create([
                    'from_status' => $previous,
                    'to_status' => $status,
                    'actor_type' => Order::ACTOR_SYSTEM,
                    'reason' => mb_substr('Alshrouq: '.$providerStatus.($reason ? " ({$reason})" : ''), 0, 500),
                ]);
            }

            $order->save();

            return [$moved, $previous, $order];
        });

        if (! $moved) {
            Log::info('Alshrouq update ignored', ['order_id' => $order->id, 'status' => $previous, 'update' => $status]);

            return false;
        }

        $this->notify($order, $previous);

        return true;
    }

    /**
     * Tell the customer; never fails the caller (a webhook Alshrouq would retry,
     * or a dispatch already saved).
     */
    protected function notify(Order $order, string $previous): void
    {
        try {
            $this->notifications->notifyOrderStatus($order, $order->cancellation_reason, $previous);
        } catch (\Throwable $e) {
            Log::error('Order delivery status notification failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
