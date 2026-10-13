<?php

namespace App\Services;

use App\Exceptions\CustomOrderException;
use App\Exceptions\OrderStateException;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Firebase\CaptainOrderFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Status changes made by an admin from the panel, on store orders and on
 * personal shopper (custom) orders. The customer is notified like for any
 * other status change (in-app + push, in their language); a cancellation
 * gets its own text saying the app's administration cancelled it.
 *
 * Store orders: once paid, the admin may move an order forward along the
 * normal flow (skipping steps) or cancel it until it is delivered. An order
 * with the delivery company follows the company's updates; it can only be
 * marked delivered.
 *
 * Custom orders: the admin may accept / start an order that has a shopper,
 * mark a paid order picked up or delivered, and cancel one the app would
 * still let be cancelled (before payment: paid ones need a refund first).
 */
class AdminOrderStatusService
{
    /**
     * Store order flow: status => rank. The delivery company's statuses sit
     * between `ready` and `delivered`.
     */
    public const STORE_FLOW = [
        Order::STATUS_PENDING => 0,
        Order::STATUS_ACCEPTED => 1,
        Order::STATUS_PROCESSING => 2,
        Order::STATUS_READY => 3,
        Order::STATUS_OUT_FOR_DELIVERY => 4,
        Order::STATUS_DELIVERED => 5,
    ];

    /**
     * Statuses an admin may set on a store order => timestamp column.
     */
    public const STORE_TARGETS = [
        Order::STATUS_ACCEPTED => 'accepted_at',
        Order::STATUS_PROCESSING => 'preparing_at',
        Order::STATUS_READY => 'ready_at',
        Order::STATUS_OUT_FOR_DELIVERY => 'dispatched_at',
        Order::STATUS_DELIVERED => 'delivered_at',
        Order::STATUS_CANCELLED => 'cancelled_at',
    ];

    /**
     * Statuses an admin may set on a custom order => timestamp column.
     */
    public const CUSTOM_TARGETS = [
        CustomOrder::STATUS_ACCEPTED => 'accepted_at',
        CustomOrder::STATUS_IN_PROGRESS => 'started_at',
        CustomOrder::STATUS_ORDER_PICKED_UP => null,
        CustomOrder::STATUS_COMPLETED => 'completed_at',
        CustomOrder::STATUS_CANCELLED => 'cancelled_at',
    ];

    public const STORE_CANCELLED_KEYS = ['notifications.order_admin_cancelled_title', 'notifications.order_admin_cancelled_body'];

    public const CUSTOM_CANCELLED_KEYS = ['notifications.custom_order_admin_cancelled_title', 'notifications.custom_order_admin_cancelled_body'];

    public function __construct(
        protected NotificationService $notifications,
        protected CheckoutService $checkout,
        protected CaptainOrderFeed $captainFeed,
        protected CustomOrderService $customOrders,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Store orders
    |--------------------------------------------------------------------------
    */

    /**
     * Statuses the admin may move the store order to, in flow order.
     *
     * @return list<string>
     */
    public function storeTargets(Order $order): array
    {
        if (! $order->isPaid() || ! in_array($order->status, Order::ACTIVE_STATUSES, true)) {
            return [];
        }

        // With the delivery company: its updates drive the order
        if (in_array($order->status, Order::DELIVERY_STATUSES, true)) {
            return [Order::STATUS_DELIVERED];
        }

        $rank = self::STORE_FLOW[$order->status];
        $forward = array_keys(array_filter(self::STORE_FLOW, fn (int $step) => $step > $rank));

        return [...$forward, Order::STATUS_CANCELLED];
    }

    /**
     * @throws OrderStateException  the status is not allowed (re-checked under lock)
     */
    public function updateStoreOrder(Order $order, string $status, User $admin, ?string $reason = null): Order
    {
        $reason = filled($reason) ? trim($reason) : null;

        [$order, $previous] = DB::transaction(function () use ($order, $status, $admin, $reason) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $previous = $order->status;

            if (! in_array($status, $this->storeTargets($order), true)) {
                throw OrderStateException::invalidTransition($status, $previous);
            }

            $order->forceFill(['status' => $status, self::STORE_TARGETS[$status] => now()]);

            if ($status === Order::STATUS_CANCELLED) {
                $order->forceFill(['cancellation_reason' => $reason, 'cancelled_by' => Order::ACTOR_SYSTEM]);
            }

            $order->save();

            $order->statusHistories()->create([
                'from_status' => $previous,
                'to_status' => $status,
                'actor_type' => Order::ACTOR_SYSTEM,
                'actor_id' => $admin->getKey(),
                'reason' => $reason,
            ]);

            if ($status === Order::STATUS_CANCELLED) {
                // Reserved stock and the promo use go back, as when the store cancels
                $this->checkout->releaseStock($order);

                if ($order->promo_code_id) {
                    $order->promoCode()->where('used_count', '>', 0)->decrement('used_count');
                }
            }

            return [$order, $previous];
        });

        // No captain is needed any more (no-op when the order was never in the feed)
        if (in_array($status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED], true)) {
            $this->captainFeed->remove($order);
        }

        $this->safely(fn () => $this->notifications->notifyOrderStatus(
            $order,
            $reason,
            $previous,
            $status === Order::STATUS_CANCELLED ? self::STORE_CANCELLED_KEYS : null,
        ), ['order_id' => $order->id]);

        return $order;
    }

    /*
    |--------------------------------------------------------------------------
    | Custom (personal shopper) orders
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string>
     */
    public function customTargets(CustomOrder $order): array
    {
        $targets = match (true) {
            $order->status === CustomOrder::STATUS_PENDING && $order->hasShopper() => [CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_IN_PROGRESS],
            $order->status === CustomOrder::STATUS_ACCEPTED => [CustomOrder::STATUS_IN_PROGRESS],
            $order->isPaid() => $this->customDeliveryTargets($order),
            default => [],
        };

        // A paid order needs a refund, which the cancellation would not do
        if ($order->isCancellable() && ! $order->isDraft() && ! $order->isPaid()) {
            $targets[] = CustomOrder::STATUS_CANCELLED;
        }

        return $targets;
    }

    /**
     * Forward along the delivery flow: picked up (on the way), then delivered.
     *
     * @return list<string>
     */
    protected function customDeliveryTargets(CustomOrder $order): array
    {
        $current = array_search($order->status, CustomOrder::DELIVERY_FLOW, true);

        if ($current === false || $order->status === CustomOrder::STATUS_COMPLETED) {
            return [];
        }

        return array_values(array_filter(
            [CustomOrder::STATUS_ORDER_PICKED_UP, CustomOrder::STATUS_COMPLETED],
            fn (string $status) => array_search($status, CustomOrder::DELIVERY_FLOW, true) > $current,
        ));
    }

    /**
     * @throws CustomOrderException  the status is not allowed (re-checked under lock)
     */
    public function updateCustomOrder(CustomOrder $order, string $status, User $admin, ?string $reason = null): CustomOrder
    {
        $reason = filled($reason) ? trim($reason) : null;
        $previous = $order->status;

        if ($status === CustomOrder::STATUS_CANCELLED) {
            if (! in_array($status, $this->customTargets($order->fresh()), true)) {
                throw CustomOrderException::notCancellable($order->status);
            }

            // Same rules and side effects (pending bids closed) as any other cancellation
            $order = $this->customOrders->cancel($order, CustomOrder::ACTOR_SYSTEM, $reason);
        } else {
            [$order, $previous] = DB::transaction(function () use ($order, $status) {
                /** @var CustomOrder $order */
                $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->getKey());
                $previous = $order->status;

                if (! in_array($status, $this->customTargets($order), true)) {
                    throw CustomOrderException::invalidTransition($previous, $status);
                }

                $order->status = $status;

                if ($column = self::CUSTOM_TARGETS[$status]) {
                    $order->{$column} = now();
                }

                // Started straight from pending: it was accepted on the way
                if ($status === CustomOrder::STATUS_IN_PROGRESS) {
                    $order->accepted_at ??= now();
                }

                $order->save();

                return [$order, $previous];
            });
        }

        $this->notifications->notifyCustomOrderStatusChanged(
            $order,
            $previous,
            $status === CustomOrder::STATUS_CANCELLED ? self::CUSTOM_CANCELLED_KEYS : null,
        );

        return $order;
    }

    /**
     * The status change is saved: a failing notification must not undo it.
     *
     * @param  array<string, mixed>  $context
     */
    protected function safely(callable $notify, array $context): void
    {
        try {
            $notify();
        } catch (Throwable $e) {
            Log::error('Admin order status notification failed', $context + ['error' => $e->getMessage()]);
        }
    }
}
