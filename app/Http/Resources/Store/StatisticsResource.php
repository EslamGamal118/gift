<?php

namespace App\Http\Resources\Store;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The merchant's statistics screen. Wraps StoreStatisticsService::build().
 * Amounts are Money::format() objects ({amount, currency, formatted}); chart
 * days run Saturday to Friday of the current week.
 */
class StatisticsResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $currency = $data['currency'];
        $counters = $data['counters'];
        $growth = $this->growth($data['week_earnings'], $data['last_week_earnings']);
        $locale = app()->getLocale();

        $day = fn (array $day) => [
            'day' => strtolower($day['date']->englishDayOfWeek),
            'label' => $day['date']->copy()->locale($locale)->dayName,
            'date' => $day['date']->toDateString(),
        ];

        $running = 0.0;

        return [
            'period' => [
                'from' => $data['week_start']->toDateString(),
                'to' => $data['week_end']->toDateString(),
                'timezone' => $data['timezone'],
            ],

            'weekly_earnings' => Money::format($data['week_earnings'], $currency),
            // "+15% مقارنة بالأسبوع الماضي": this week so far vs the same stretch of last week
            'earnings_growth' => [
                'value' => $growth,
                'label' => $growth === null ? __('statistics.no_last_week_earnings') : __('dashboard.vs_last_week', ['change' => $this->percent($growth)]),
                'trend' => $this->trend($growth),
            ],

            // No payout flow exists yet: the button is shown disabled
            'payout_action' => [
                'enabled' => false,
                'label' => __('statistics.request_payout'),
                'endpoint' => null,
            ],

            'completed_orders_count' => $counters['completed'],
            'on_the_way_orders_count' => $counters['on_the_way'],
            'cancelled_orders_count' => $counters['cancelled'],
            'average_order_value' => Money::format($counters['average_order_value'], $currency),

            // Earnings accumulated day by day over the week (line chart)
            'profit_trend' => [
                'growth_percentage' => $growth === null ? null : $this->percent($growth),
                'trend' => $this->trend($growth),
                'currency' => $currency,
                'chart_data' => array_map(function (array $point) use ($day, &$running) {
                    $running = round($running + $point['amount'], 2);

                    return $day($point) + ['value' => $running];
                }, $data['days']),
            ],

            // Each day's own sales (bar chart)
            'daily_sales' => array_map(fn (array $point) => $day($point) + [
                'amount' => $point['amount'],
                'orders_count' => $point['orders_count'],
            ], $data['days']),

            'order_distribution' => $this->distribution($counters),

            'top_products' => array_map(fn (array $product, int $i) => [
                'rank' => $i + 1,
                'product_id' => $product['product_id'],
                'product_name' => $product['name'],
                'image' => $this->fileUrl($product['image']),
                'orders_count' => $product['orders_count'],
                'orders_count_label' => trans_choice('statistics.orders_count', $product['orders_count'], ['count' => $product['orders_count']]),
                'quantity_sold' => $product['quantity'],
                'total_revenue' => Money::format($product['revenue'], $currency),
            ], $data['top_products'], array_keys($data['top_products'])),

            'customer_rating' => [
                'rating_average' => $data['rating_average'],
                'total_reviews_count' => $data['rating_count'],
                'total_reviews_label' => trans_choice('statistics.reviews_count', $data['rating_count'], ['count' => $data['rating_count']]),
            ],
        ];
    }

    /**
     * Completed / cancelled / processing shares of all the store's orders,
     * whole percents summing to 100 (largest remainder), all 0 without orders.
     *
     * @param  array{completed: int, cancelled: int, processing: int, total: int}  $counters
     * @return array<string, mixed>
     */
    protected function distribution(array $counters): array
    {
        $parts = ['completion' => $counters['completed'], 'cancellation' => $counters['cancelled'], 'processing' => $counters['processing']];
        $total = $counters['total'];
        $shares = array_fill_keys(array_keys($parts), 0);

        if ($total > 0) {
            $exact = array_map(fn (int $count) => $count * 100 / $total, $parts);
            $shares = array_map(fn (float $value) => (int) floor($value), $exact);
            $remainders = array_map(fn (float $value, int $floor) => $value - $floor, $exact, $shares);
            $keys = array_keys($parts);
            arsort($remainders);

            foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($shares)) as $index) {
                $shares[$keys[$index]]++;
            }
        }

        return [
            'completion_percentage' => $shares['completion'],
            'cancellation_percentage' => $shares['cancellation'],
            'processing_percentage' => $shares['processing'],
            'total_orders' => $total,
            'segments' => [
                ['key' => 'completed', 'label' => __('statistics.distribution.completed'), 'percentage' => $shares['completion'], 'count' => $counters['completed']],
                ['key' => 'cancelled', 'label' => __('statistics.distribution.cancelled'), 'percentage' => $shares['cancellation'], 'count' => $counters['cancelled']],
                ['key' => 'processing', 'label' => __('statistics.distribution.processing'), 'percentage' => $shares['processing'], 'count' => $counters['processing']],
            ],
        ];
    }

    /**
     * Percentage change; null when last week earned nothing to compare with.
     */
    protected function growth(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return $current <= 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    protected function trend(?float $growth): string
    {
        return match (true) {
            $growth === null, $growth > 0 => 'up',
            $growth < 0 => 'down',
            default => 'flat',
        };
    }

    /**
     * 15.0 -> "+15%", -2.5 -> "-2.5%".
     */
    protected function percent(float $value): string
    {
        return ($value > 0 ? '+' : '').rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';
    }
}
