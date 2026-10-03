<?php

namespace App\Http\Resources\Shopper;

use App\Services\StoreDashboardService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The personal shopper's statistics screen. Wraps ShopperStatisticsService::build().
 */
class ShopperStatisticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data     = $this->resource;
        $counters = $data['counters'];
        $growth   = $this->growth($data['week_earnings'], $data['last_week_earnings']);
        $rate     = StoreDashboardService::acceptanceRate($counters['accepted_orders'], $counters['declined_orders']);
        $minutes  = $data['average_shopping_minutes'];
        $locale   = app()->getLocale();

        return [
            'weekly_earnings' => Money::format($data['week_earnings'], $data['currency']) + [
                'growth_percentage' => $growth,
                'growth_label'      => $this->label($growth),
                'trend'             => $this->trend($growth),
                'period'            => [
                    'from'     => $data['week_start']->toDateString(),
                    'to'       => $data['week_end']->toDateString(),
                    'timezone' => $data['timezone'],
                ],
            ],

            'metrics' => [
                'completed_orders'              => $counters['completed_orders'],
                'active_orders'                 => $counters['active_tasks'],
                'acceptance_rate'               => $rate,   // null before any accept / decline
                'acceptance_rate_label'         => $rate === null ? null : $this->number($rate).'%',
                'average_shopping_time_minutes' => $minutes,   // null before any shopping trip
                'average_shopping_time_label'   => $minutes === null ? null : trans_choice('custom_orders.statistics.minutes', $minutes, ['count' => $minutes]),
            ],

            // The shopper's profile specialties (custom order items have no category): equal shares
            'categories_performance' => array_map(fn (array $category) => [
                'category_id'   => $category['id'],
                'category_name' => $category['name'],
                'percentage'    => $category['percentage'],
            ], $data['categories']),
            'categories_performance_basis' => 'profile_specialties',

            'earnings_trend' => [
                'currency'           => $data['currency'],
                'days'               => array_map(fn (array $day) => [
                    'day'    => strtolower($day['date']->englishDayOfWeek),
                    'label'  => $day['date']->copy()->locale($locale)->dayName,
                    'date'   => $day['date']->toDateString(),
                    'amount' => $day['amount'],
                ], $data['days']),
                // Same week-over-week comparison as weekly_earnings
                'trend_growth'       => $growth,
                'trend_growth_label' => $this->label($growth),
            ],

            'customer_rating' => [
                'average_rating'      => $data['rating_average'],
                'total_reviews_count' => $data['rating_count'],
            ],
        ];
    }

    /**
     * Percentage change of two amounts; null when last week had nothing to compare with.
     */
    protected function growth(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return $current <= 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    protected function label(?float $growth): ?string
    {
        return $growth === null ? null : ($growth > 0 ? '+' : '').$this->number($growth).'%';
    }

    protected function trend(?float $growth): string
    {
        return match (true) {
            $growth === null, $growth > 0 => 'up',
            $growth < 0                   => 'down',
            default                       => 'flat',
        };
    }

    /**
     * 21.0 -> "21", 21.5 -> "21.5".
     */
    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
