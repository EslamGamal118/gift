<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the merchant app's home screen. Only strictly `paid` orders are
 * counted, earned from or listed; unpaid (`pending`), failed and refunded
 * payments never reach any number here.
 *
 * - orders_today: orders placed since midnight (store timezone), compared with
 *   the same stretch of the same weekday last week.
 * - new_orders: live count of orders waiting for the store (not period bound).
 * - completed / acceptance rate / revenue: over the requested period.
 *   Acceptance = accepted / (accepted + rejected by the store before accepting);
 *   customer cancellations and undecided orders do not count against it.
 *   Revenue = order totals of the period's paid orders that were not cancelled.
 */
class StoreDashboardService
{
    public const PERIODS = ['today', 'week', 'month'];

    public function __construct(
        protected StoreOrderService $orders,
        protected NotificationService $notifications,
    ) {}

    /**
     * @return array{
     *     period: string, currency: string, orders_today: int, orders_same_day_last_week: int,
     *     new_orders: int, completed_orders: int, accepted_orders: int, rejected_orders: int, revenue: float
     * }
     */
    public function stats(User $store, string $period = 'month'): array
    {
        [$todayFrom] = $this->orders->dateBounds(['date_range' => 'today']);
        [$periodFrom] = $this->orders->dateBounds(['date_range' => $period]);
        $now = Carbon::now('UTC');
        $weekAgoFrom = $todayFrom->copy()->subWeek();
        $weekAgoTo = $now->copy()->subWeek();

        $inPeriod = 'created_at >= ?';

        $row = $this->paidOrders($store)
            ->toBase()
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) AS orders_today', [$todayFrom, $now])
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) AS orders_same_day_last_week', [$weekAgoFrom, $weekAgoTo])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_orders', [Order::STATUS_PENDING])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND status = ? THEN 1 ELSE 0 END) AS completed_orders", [$periodFrom, Order::STATUS_DELIVERED])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted_orders", [$periodFrom])
            ->selectRaw(
                "SUM(CASE WHEN {$inPeriod} AND accepted_at IS NULL AND status = ? AND cancelled_by = ? THEN 1 ELSE 0 END) AS rejected_orders",
                [$periodFrom, Order::STATUS_CANCELLED, Order::ACTOR_STORE],
            )
            ->selectRaw("COALESCE(SUM(CASE WHEN {$inPeriod} AND status <> ? THEN total_amount ELSE 0 END), 0) AS revenue", [$periodFrom, Order::STATUS_CANCELLED])
            ->first();

        return [
            'period' => $period,
            'currency' => (string) config('stores.delivery.currency', 'SAR'),
            'orders_today' => (int) $row->orders_today,
            'orders_same_day_last_week' => (int) $row->orders_same_day_last_week,
            'new_orders' => (int) $row->new_orders,
            'completed_orders' => (int) $row->completed_orders,
            'accepted_orders' => (int) $row->accepted_orders,
            'rejected_orders' => (int) $row->rejected_orders,
            'revenue' => round((float) $row->revenue, 2),
        ];
    }

    /**
     * Latest paid orders with what the order card needs.
     *
     * @return Collection<int, Order>
     */
    public function recentOrders(User $store, int $limit = 5): Collection
    {
        return $this->paidOrders($store)
            ->with(['user', 'items'])
            ->withCount('items')
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function unreadNotificationsCount(User $store): int
    {
        return $this->notifications->unreadCount($store);
    }

    /**
     * Percentage change, null when there is nothing to compare against.
     */
    public static function changePercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Share of decided orders the store accepted, null before any decision.
     */
    public static function acceptanceRate(int $accepted, int $rejected): ?float
    {
        $decided = $accepted + $rejected;

        return $decided === 0 ? null : round($accepted / $decided * 100, 1);
    }

    protected function paidOrders(User $store): Builder
    {
        return Order::query()->forStore($store->id)->visibleToStore()->paid();
    }
}
