<?php

namespace App\Support;

use App\Exceptions\OrderStateException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The order lifecycle and the only way to move an order between statuses.
 *
 *   pending_payment ──(paid)──▶ pending ──accept──▶ accepted ──start_preparing──▶ processing
 *        ──ready──▶ ready ──dispatch──▶ out_for_delivery ──deliver──▶ delivered
 *
 *   cancel: pending | accepted | processing ──▶ cancelled
 *
 * Every transition runs under a row lock, stamps the matching timestamp and
 * appends an order_status_histories row. Which actor may fire which action is
 * declared in ACTIONS so controllers only need to name the action.
 */
class OrderStateMachine
{
    public const ACTION_ACCEPT = 'accept';

    public const ACTION_START_PREPARING = 'start_preparing';

    public const ACTION_READY = 'ready';

    public const ACTION_DISPATCH = 'dispatch';

    public const ACTION_DELIVER = 'deliver';

    public const ACTION_CANCEL = 'cancel';

    /**
     * action => [from statuses, to status, actors allowed, timestamp column]
     *
     * @var array<string, array{from: list<string>, to: string, actors: list<string>, stamp: string|null}>
     */
    public const ACTIONS = [
        self::ACTION_ACCEPT => [
            'from' => [Order::STATUS_PENDING],
            'to' => Order::STATUS_ACCEPTED,
            'actors' => [Order::ACTOR_STORE],
            'stamp' => 'accepted_at',
        ],
        self::ACTION_START_PREPARING => [
            'from' => [Order::STATUS_ACCEPTED],
            'to' => Order::STATUS_PROCESSING,
            'actors' => [Order::ACTOR_STORE],
            'stamp' => 'preparing_at',
        ],
        self::ACTION_READY => [
            'from' => [Order::STATUS_PROCESSING],
            'to' => Order::STATUS_READY,
            'actors' => [Order::ACTOR_STORE],
            'stamp' => 'ready_at',
        ],
        self::ACTION_DISPATCH => [
            'from' => [Order::STATUS_READY],
            'to' => Order::STATUS_OUT_FOR_DELIVERY,
            'actors' => [Order::ACTOR_STORE, Order::ACTOR_SYSTEM],
            'stamp' => 'dispatched_at',
        ],
        self::ACTION_DELIVER => [
            'from' => [Order::STATUS_OUT_FOR_DELIVERY],
            'to' => Order::STATUS_DELIVERED,
            'actors' => [Order::ACTOR_CAPTAIN, Order::ACTOR_STORE, Order::ACTOR_SYSTEM],
            'stamp' => 'delivered_at',
        ],
        self::ACTION_CANCEL => [
            'from' => [Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_PROCESSING],
            'to' => Order::STATUS_CANCELLED,
            'actors' => [Order::ACTOR_STORE, Order::ACTOR_CUSTOMER, Order::ACTOR_SYSTEM],
            'stamp' => 'cancelled_at',
        ],
    ];

    /**
     * Actions an actor may perform on the order in its current status.
     *
     * @return list<string>
     */
    public function availableActions(Order $order, string $actor = Order::ACTOR_STORE): array
    {
        $actions = [];

        foreach (self::ACTIONS as $action => $rule) {
            if (in_array($order->status, $rule['from'], true) && in_array($actor, $rule['actors'], true)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    public function can(Order $order, string $action, string $actor = Order::ACTOR_STORE): bool
    {
        return in_array($action, $this->availableActions($order, $actor), true);
    }

    /**
     * Apply an action. Returns the fresh order; throws when the transition is
     * not allowed from the current status (re-read under lock, so two
     * concurrent "accept" clicks cannot both succeed).
     *
     * @param  array<string, mixed>  $attributes  Extra columns to set with the transition
     *
     * @throws OrderStateException
     */
    public function apply(Order $order, string $action, string $actor, ?User $by = null, ?string $reason = null, array $attributes = []): Order
    {
        $rule = self::ACTIONS[$action] ?? throw OrderStateException::unknownAction($action);

        return DB::transaction(function () use ($order, $action, $rule, $actor, $by, $reason, $attributes) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $from = $order->status;

            if (! in_array($from, $rule['from'], true) || ! in_array($actor, $rule['actors'], true)) {
                throw OrderStateException::invalidTransition($action, $from);
            }

            $changes = $attributes + ['status' => $rule['to']];

            if ($rule['stamp'] !== null) {
                $changes[$rule['stamp']] = now();
            }

            $order->forceFill($changes)->save();

            $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $rule['to'],
                'actor_type' => $actor,
                'actor_id' => $by?->getKey(),
                'reason' => $reason,
            ]);

            return $order;
        });
    }

    /**
     * Human-readable status for the given locale (used in notifications and cards).
     */
    public static function statusLabel(string $status, ?string $locale = null): string
    {
        $line = __('orders.statuses.'.$status, [], $locale);

        return is_string($line) ? $line : $status;
    }
}
