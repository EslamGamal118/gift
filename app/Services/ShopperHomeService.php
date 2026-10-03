<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the personal shopper app's home screen, over the shopper's own
 * custom orders (one aggregate query):
 *
 * - active_tasks: orders being worked on (accepted, shopping, waiting for an
 *   alternative or for the payment), with the orders taken on in the last 7
 *   days compared with the 7 days before.
 * - new_orders: orders assigned to the shopper still waiting for their answer.
 * - completed: orders completed (paid).
 * - acceptance rate = accepted / (accepted + declined by the shopper before
 *   accepting); customer cancellations and undecided orders do not count.
 * - earnings = the shopper's fees on paid orders (refunded ones excluded).
 */
class ShopperHomeService
{
    /**
     * Statuses of an order the shopper is working on.
     *
     * @var list<string>
     */
    public const ACTIVE_TASK_STATUSES = [
        CustomOrder::STATUS_ACCEPTED,
        CustomOrder::STATUS_IN_PROGRESS,
        CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE,
        CustomOrder::STATUS_WAITING_FOR_PAYMENT,
    ];

    /**
     * @return array{
     *     currency: string, active_tasks: int, accepted_this_week: int, accepted_last_week: int,
     *     new_orders: int, completed_orders: int, accepted_orders: int, declined_orders: int, earnings: float
     * }
     */
    public function stats(User $shopper): array
    {
        $now         = Carbon::now();
        $weekAgo     = $now->copy()->subWeek();
        $twoWeeksAgo = $now->copy()->subWeeks(2);
        $active      = implode(', ', array_fill(0, count(self::ACTIVE_TASK_STATUSES), '?'));

        $row = CustomOrder::query()
            ->forShopper($shopper->id)
            ->toBase()
            ->selectRaw("SUM(CASE WHEN status IN ({$active}) THEN 1 ELSE 0 END) AS active_tasks", self::ACTIVE_TASK_STATUSES)
            ->selectRaw('SUM(CASE WHEN accepted_at >= ? THEN 1 ELSE 0 END) AS accepted_this_week', [$weekAgo])
            ->selectRaw('SUM(CASE WHEN accepted_at >= ? AND accepted_at < ? THEN 1 ELSE 0 END) AS accepted_last_week', [$twoWeeksAgo, $weekAgo])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_orders', [CustomOrder::STATUS_PENDING])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_orders', [CustomOrder::STATUS_COMPLETED])
            ->selectRaw('SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted_orders')
            ->selectRaw(
                'SUM(CASE WHEN accepted_at IS NULL AND status = ? AND cancelled_by = ? THEN 1 ELSE 0 END) AS declined_orders',
                [CustomOrder::STATUS_CANCELLED, CustomOrder::ACTOR_SHOPPER],
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_status = ? THEN shopper_fees ELSE 0 END), 0) AS earnings', [CustomOrder::PAYMENT_PAID])
            ->first();

        return [
            'currency'           => (string) config('custom_orders.currency', 'SAR'),
            'active_tasks'       => (int) $row->active_tasks,
            'accepted_this_week' => (int) $row->accepted_this_week,
            'accepted_last_week' => (int) $row->accepted_last_week,
            'new_orders'         => (int) $row->new_orders,
            'completed_orders'   => (int) $row->completed_orders,
            'accepted_orders'    => (int) $row->accepted_orders,
            'declined_orders'    => (int) $row->declined_orders,
            'earnings'           => round((float) $row->earnings, 2),
        ];
    }

    /**
     * Newest orders waiting for the shopper's answer, with what the card shows.
     *
     * @return Collection<int, CustomOrder>
     */
    public function newOrders(User $shopper, int $limit = 10): Collection
    {
        return CustomOrder::query()
            ->forShopper($shopper->id)
            ->where('status', CustomOrder::STATUS_PENDING)
            ->with('user:id,name,avatar')
            ->withCount('items')
            ->latest('submitted_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
