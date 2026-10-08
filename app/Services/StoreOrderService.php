<?php

namespace App\Services;

use App\Exceptions\OrderStateException;
use App\Models\Order;
use App\Models\User;
use App\Services\Firebase\CaptainOrderFeed;
use App\Support\OrderStateMachine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Order management for the merchant (store) app: listing/filtering the
 * store's orders and running the status workflow, notifying the customer
 * on every transition.
 */
class StoreOrderService
{
    /**
     * Named ranges accepted by the list filter.
     */
    public const DATE_RANGES = ['today', 'yesterday', 'week', 'month'];

    /**
     * Status the store asks for (POST /store/orders/{order}/status) => workflow
     * action. Values follow the card badges; `send_to_captain` and
     * `out_for_delivery` both hand the order over for delivery: to the delivery
     * company (Alshrouq) when enabled, else to the app's captains.
     */
    public const STATUS_ACTIONS = [
        'accepted' => OrderStateMachine::ACTION_ACCEPT,
        'preparing' => OrderStateMachine::ACTION_START_PREPARING,
        'ready_for_pickup' => OrderStateMachine::ACTION_READY,
        'send_to_captain' => OrderStateMachine::ACTION_DISPATCH,
        'out_for_delivery' => OrderStateMachine::ACTION_DISPATCH,
        'completed' => OrderStateMachine::ACTION_DELIVER,
        'cancelled' => OrderStateMachine::ACTION_CANCEL,
    ];

    public function __construct(
        protected OrderStateMachine $machine,
        protected NotificationService $notifications,
        protected CheckoutService $checkout,
        protected CaptainOrderFeed $captainFeed,
        protected StoreOrderDeliveryService $delivery,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{status?: list<string>|null, status_type?: string|null, search?: string|null, date_range?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function paginate(User $store, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($store, $filters)
            ->with(['user', 'items', 'gift'])
            ->withCount('items')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Number of orders per status for the tab badges (ignores the status filter).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function counts(User $store, array $filters = []): array
    {
        $counts = $this->query($store, ['status' => null] + $filters)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = ['all' => (int) $counts->sum(), 'active' => 0];

        foreach ([Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_PROCESSING, Order::STATUS_READY, Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, ...Order::DELIVERY_STATUSES] as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        $result['active'] = (int) $counts->only(Order::ACTIVE_STATUSES)->sum();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(User $store, array $filters): Builder
    {
        $statuses = $filters['status'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        [$from, $to] = $this->dateBounds($filters);

        return Order::query()
            ->forStore($store->id)
            ->visibleToStore()
            ->when($statuses !== null, fn (Builder $q) => $q->whereIn('status', $statuses))
            ->when($search !== '', function (Builder $q) use ($search) {
                $digits = preg_replace('/\D+/', '', $search) ?? '';

                $q->where(function (Builder $w) use ($search, $digits) {
                    $w->where('order_number', 'like', "%{$search}%")
                        ->orWhere('shipping_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"));

                    if ($digits !== '') {
                        $w->orWhere('shipping_phone', 'like', "%{$digits}%");
                    }

                    // Plain numeric search also matches the internal order id
                    if (ctype_digit($search)) {
                        $w->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('created_at', '<=', $to));
    }

    /**
     * Full details for the order screen.
     */
    public function details(Order $order): Order
    {
        return $order->load([
            'user',
            'captain',
            'gift',
            'items.addons',
            'deliverySlot',
            'statusHistories.actor',
            'transactions' => fn ($q) => $q->latest('id')->limit(5),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow actions
    |--------------------------------------------------------------------------
    */

    /**
     * Move the order to the requested status (a STATUS_ACTIONS key). Flow checks
     * run again under lock in the state machine; the customer is notified.
     */
    public function updateStatus(Order $order, User $store, string $status, ?string $reason = null): Order
    {
        return match (self::STATUS_ACTIONS[$status] ?? null) {
            OrderStateMachine::ACTION_ACCEPT => $this->accept($order, $store),
            OrderStateMachine::ACTION_START_PREPARING => $this->startPreparing($order, $store),
            OrderStateMachine::ACTION_READY => $this->markReady($order, $store),
            OrderStateMachine::ACTION_DISPATCH => $this->dispatchToCaptains($order, $store),
            OrderStateMachine::ACTION_DELIVER => $this->markDelivered($order, $store),
            OrderStateMachine::ACTION_CANCEL => $this->cancel($order, $store, (string) $reason),
            default => throw OrderStateException::unknownAction($status),
        };
    }

    /**
     * Statuses the store may send next from the order's current status.
     *
     * @return list<string>
     */
    public function nextStatuses(Order $order): array
    {
        $actions = $this->availableActions($order);

        return array_keys(array_filter(self::STATUS_ACTIONS, fn (string $action) => in_array($action, $actions, true)));
    }

    public function accept(Order $order, User $store): Order
    {
        return $this->transition($order, OrderStateMachine::ACTION_ACCEPT, $store);
    }

    public function startPreparing(Order $order, User $store): Order
    {
        return $this->transition($order, OrderStateMachine::ACTION_START_PREPARING, $store);
    }

    public function markReady(Order $order, User $store): Order
    {
        return $this->transition($order, OrderStateMachine::ACTION_READY, $store);
    }

    /**
     * The order no longer needs a captain, so it leaves the live feed.
     */
    public function markDelivered(Order $order, User $store): Order
    {
        $order = $this->transition($order, OrderStateMachine::ACTION_DELIVER, $store);

        $this->captainFeed->remove($order);

        return $order;
    }

    /**
     * Release the order for delivery.
     *
     * With the delivery company (Alshrouq) enabled, the order is created there
     * and follows its statuses (StoreOrderDeliveryService); if Alshrouq refuses
     * it the order stays `ready` (422).
     *
     * Otherwise the store never picks a captain: every active captain is told
     * the order is available and one of them takes it from the captain app
     * (which sets `captain_id`). Once the status is committed the order is
     * written to the Firebase live feed, next to the push.
     *
     * @throws \App\Exceptions\DeliveryException
     */
    public function dispatchToCaptains(Order $order, User $store): Order
    {
        if ($this->delivery->enabled()) {
            return $this->delivery->dispatch($order, $store);
        }

        $order = $this->transition($order, OrderStateMachine::ACTION_DISPATCH, $store);

        $this->captainFeed->publish($order);
        $this->notifications->notifyCaptainsOrderAvailable($order);

        return $order;
    }

    /**
     * Cancel with a mandatory reason; reserved stock goes back to the shelf.
     * A paid order keeps `payment_status = paid` - refunding is a separate step.
     */
    public function cancel(Order $order, User $store, string $reason): Order
    {
        $order = $this->transition($order, OrderStateMachine::ACTION_CANCEL, $store, $reason, [
            'cancellation_reason' => $reason,
            'cancelled_by' => Order::ACTOR_STORE,
        ], notify: false);

        $this->checkout->releaseStock($order);

        if ($order->promo_code_id) {
            $order->promoCode()->where('used_count', '>', 0)->decrement('used_count');
        }

        $this->notifications->notifyOrderStatus($order, $reason, $this->previousStatus($order));

        return $order;
    }

    /**
     * @return list<string>
     */
    public function availableActions(Order $order): array
    {
        return $this->machine->availableActions($order, Order::ACTOR_STORE);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(Order $order, string $action, User $store, ?string $reason = null, array $attributes = [], bool $notify = true): Order
    {
        $order = $this->machine->apply($order, $action, Order::ACTOR_STORE, $store, $reason, $attributes);

        if ($notify) {
            $this->notifications->notifyOrderStatus($order, $reason, $this->previousStatus($order));
        }

        return $order;
    }

    /**
     * Status the order left in its latest transition (recorded under lock).
     */
    protected function previousStatus(Order $order): ?string
    {
        return $order->statusHistories()->reorder()->latest('id')->value('from_status');
    }

    /**
     * Resolve `date_range` (today / yesterday / week / month) or explicit
     * `date_from` / `date_to` (Y-m-d, store timezone) into UTC bounds.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public function dateBounds(array $filters): array
    {
        $tz = config('checkout.delivery.timezone', config('app.timezone'));
        $now = Carbon::now($tz);

        [$from, $to] = match ($filters['date_range'] ?? null) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'week' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            default => [
                ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'], $tz)->startOfDay() : null,
                ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'], $tz)->endOfDay() : null,
            ],
        };

        return [$from?->utc(), $to?->utc()];
    }
}
