<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The merchant's statistics screen.
 *
 * Only orders that reached the store count (Order::visibleToStore(): the
 * gateway confirmed the payment). Money is earned by paid orders that were not
 * cancelled: their totals, dated by `paid_at`.
 *
 * - Week: Saturday to Friday in the app's local timezone
 *   (`checkout.delivery.timezone`), as on the shopper's screen; this week so
 *   far is compared with the same stretch of last week.
 * - Counters, distribution and top products: all the store's orders.
 * - Rating: store_profiles.rating_avg / rating_count.
 */
class StoreStatisticsService
{
    public const TOP_PRODUCTS = 3;

    /**
     * @return array{
     *     currency: string, timezone: string, week_start: Carbon, week_end: Carbon,
     *     week_earnings: float, last_week_earnings: float,
     *     days: list<array{date: Carbon, amount: float, orders_count: int}>,
     *     counters: array{completed: int, on_the_way: int, cancelled: int, processing: int, total: int, average_order_value: float},
     *     top_products: list<array{product_id: ?int, name: string, image: ?string, quantity: int, orders_count: int, revenue: float}>,
     *     rating_average: float, rating_count: int
     * }
     */
    public function build(User $store, int $topProducts = self::TOP_PRODUCTS): array
    {
        $timezone = (string) config('checkout.delivery.timezone', 'Asia/Riyadh');
        $now = Carbon::now($timezone);
        $weekStart = $now->copy()->startOfWeek(Carbon::SATURDAY);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        // This week + last week's earning orders, grouped by local day here
        $earnings = $this->earningOrders($store)
            ->where('paid_at', '>=', $weekStart->copy()->subWeek()->utc())
            ->toBase()
            ->get(['paid_at', 'total_amount'])
            ->map(fn ($row) => ['at' => Carbon::parse($row->paid_at, 'UTC')->setTimezone($timezone), 'total' => (float) $row->total_amount]);

        $sameStretchLastWeek = $now->copy()->subWeek();
        $lastWeek = $earnings->filter(fn ($e) => $e['at']->lt($weekStart) && $e['at']->lte($sameStretchLastWeek))->sum('total');
        $thisWeek = $earnings->filter(fn ($e) => $e['at']->gte($weekStart))->values();

        $days = [];
        for ($day = $weekStart->copy(); $day->lte($weekEnd); $day->addDay()) {
            $ofDay = $thisWeek->filter(fn ($e) => $e['at']->isSameDay($day));
            $days[] = ['date' => $day->copy(), 'amount' => round($ofDay->sum('total'), 2), 'orders_count' => $ofDay->count()];
        }

        $profile = $store->storeProfile;

        return [
            'currency' => (string) config('stores.delivery.currency', 'SAR'),
            'timezone' => $timezone,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'week_earnings' => round($thisWeek->sum('total'), 2),
            'last_week_earnings' => round($lastWeek, 2),
            'days' => $days,
            'counters' => $this->counters($store),
            'top_products' => $this->topProducts($store, $topProducts),
            'rating_average' => round((float) ($profile?->rating_avg ?? 0), 1),
            'rating_count' => (int) ($profile?->rating_count ?? 0),
        ];
    }

    /**
     * Orders by stage, and the average value of an earning order.
     *
     * @return array{completed: int, on_the_way: int, cancelled: int, processing: int, total: int, average_order_value: float}
     */
    protected function counters(User $store): array
    {
        $row = $this->storeOrders($store)
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed', [Order::STATUS_DELIVERED])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) AS on_the_way', Order::ON_THE_WAY_STATUSES)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [Order::STATUS_CANCELLED])
            ->selectRaw(
                'AVG(CASE WHEN status <> ? AND payment_status = ? THEN total_amount END) AS average_order_value',
                [Order::STATUS_CANCELLED, Order::PAYMENT_PAID],
            )
            ->first();

        $total = (int) $row->total;
        $completed = (int) $row->completed;
        $cancelled = (int) $row->cancelled;

        return [
            'completed' => $completed,
            'on_the_way' => (int) $row->on_the_way,
            'cancelled' => $cancelled,
            // Everything still with the store or on the road (new ... out for delivery)
            'processing' => $total - $completed - $cancelled,
            'total' => $total,
            'average_order_value' => round((float) $row->average_order_value, 2),
        ];
    }

    /**
     * Best sellers by quantity (then revenue) over the store's earning orders.
     * Revenue is the lines' value (with add-ons), before the order's discount.
     *
     * @return list<array{product_id: ?int, name: string, image: ?string, quantity: int, orders_count: int, revenue: float}>
     */
    protected function topProducts(User $store, int $limit): array
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->earningOrders($store)->select('id'))
            ->toBase()
            // By product; lines whose product was deleted fall back to their name
            ->groupByRaw('COALESCE(CAST(product_id AS CHAR), product_name)')
            ->selectRaw('MAX(product_id) AS product_id, MAX(product_name) AS name, MAX(product_image) AS image')
            ->selectRaw('SUM(quantity) AS quantity, COUNT(DISTINCT order_id) AS orders_count, SUM(subtotal) AS revenue')
            ->orderByDesc('quantity')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'name' => (string) $row->name,
                'image' => $row->image,
                'quantity' => (int) $row->quantity,
                'orders_count' => (int) $row->orders_count,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Every order that reached the store (paid or refunded).
     */
    protected function storeOrders(User $store): Builder
    {
        return Order::query()->forStore($store->id)->visibleToStore();
    }

    /**
     * Orders the store earns from: paid and not cancelled.
     */
    protected function earningOrders(User $store): Builder
    {
        return $this->storeOrders($store)->paid()->where('status', '!=', Order::STATUS_CANCELLED);
    }
}
