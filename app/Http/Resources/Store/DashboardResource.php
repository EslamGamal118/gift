<?php

namespace App\Http\Resources\Store;

use App\Services\StoreDashboardService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The merchant home screen. Wraps the payload built by StoreDashboardController:
 * `stats` (StoreDashboardService::stats), `recent_orders`, `unread_count`,
 * `notifications`. Every figure and order here is paid (gateway-confirmed).
 *
 * `latest_orders` are the same cards as GET /store/orders (OrderCardResource).
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stats = $this->resource['stats'];
        $change = StoreDashboardService::changePercent($stats['orders_today'], $stats['orders_same_day_last_week']);
        $rate = StoreDashboardService::acceptanceRate($stats['accepted_orders'], $stats['rejected_orders']);

        return [
            'period' => $stats['period'],

            'notification_bell_icon' => [
                'has_unread' => $this->resource['unread_count'] > 0,
                'unread_count' => $this->resource['unread_count'],
            ],

            'live_performance' => [
                'today_orders_count' => $stats['orders_today'],
                // "+15% مقارنة بالأسبوع الماضي" (same stretch of the same weekday last week)
                'growth_percentage' => $change === null
                    ? __('dashboard.no_last_week_orders')
                    : __('dashboard.vs_last_week', ['change' => ($change > 0 ? '+' : '').$this->number($change).'%']),
                'growth_value' => $change,
                'trend' => match (true) {
                    $change === null, $change > 0 => 'up',
                    $change < 0 => 'down',
                    default => 'flat',
                },
            ],

            'counters' => [
                'new_orders_count' => $stats['new_orders'],
                'completed_orders_count' => $stats['completed_orders'],
                // null before the store accepted or rejected anything
                'acceptance_rate' => $rate === null ? null : $this->number($rate).'%',
                'total_earnings' => Money::format($stats['revenue'], $stats['currency']),
            ],

            'latest_orders' => OrderCardResource::collection($this->resource['recent_orders']),
        ];
    }

    /**
     * 21.0 -> "21", 21.5 -> "21.5".
     */
    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
