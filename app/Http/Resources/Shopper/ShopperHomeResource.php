<?php

namespace App\Http\Resources\Shopper;

use App\Services\StoreDashboardService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The personal shopper's home screen. Wraps the payload built by
 * ShopperHomeController: `stats` (ShopperHomeService::stats) and `new_orders`.
 */
class ShopperHomeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stats  = $this->resource['stats'];
        $growth = self::growthPercent($stats['accepted_this_week'], $stats['accepted_last_week']);
        $rate   = StoreDashboardService::acceptanceRate($stats['accepted_orders'], $stats['declined_orders']);

        return [
            'stats' => [
                'active_shopping_tasks' => [
                    'value'             => $stats['active_tasks'],
                    // Orders taken on in the last 7 days vs the 7 days before (always a number)
                    'growth_percentage' => $growth,
                    'growth_label'      => __('custom_orders.home.vs_last_week', ['change' => ($growth > 0 ? '+' : '').$this->number($growth).'%']),
                    'trend'             => match (true) {
                        $growth > 0 => 'up',
                        $growth < 0 => 'down',
                        default     => 'flat',
                    },
                ],
                'new_orders_count'       => $stats['new_orders'],
                'completed_orders_count' => $stats['completed_orders'],
                // null before the shopper has accepted or declined anything
                'acceptance_rate'        => $rate,
                'acceptance_rate_label'  => $rate === null ? null : $this->number($rate).'%',
                'earnings'               => Money::format($stats['earnings'], $stats['currency']),
            ],
            'new_orders' => ShopperHomeOrderResource::collection($this->resource['new_orders']),
        ];
    }

    /**
     * Percentage change from last week to this week. With nothing last week it
     * is +100% when there is something this week, 0% otherwise (never null).
     */
    public static function growthPercent(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * 21.0 -> "21", 21.5 -> "21.5".
     */
    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
