<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CustomOrder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The personal shopper's statistics screen.
 *
 * Weeks run Saturday to Friday in the app's local timezone
 * (`checkout.delivery.timezone`). Earnings are the shopper's fees on paid
 * custom orders, dated by `paid_at`; this week so far is compared with the
 * same stretch of last week. Completed / active / acceptance come from
 * ShopperHomeService::stats() so both screens always agree.
 */
class ShopperStatisticsService
{
    public function __construct(protected ShopperHomeService $home) {}

    /**
     * @return array{
     *     currency: string, timezone: string, week_start: Carbon, week_end: Carbon,
     *     week_earnings: float, last_week_earnings: float,
     *     days: list<array{date: Carbon, amount: float}>,
     *     counters: array<string, mixed>, average_shopping_minutes: ?int,
     *     categories: list<array{id: int, name: string, percentage: int}>,
     *     rating_average: float, rating_count: int
     * }
     */
    public function build(User $shopper): array
    {
        $timezone  = (string) config('checkout.delivery.timezone', 'Asia/Riyadh');
        $now       = Carbon::now($timezone);
        $weekStart = $now->copy()->startOfWeek(Carbon::SATURDAY);
        $weekEnd   = $weekStart->copy()->addDays(6)->endOfDay();

        // This week + last week's payments (two columns, one shopper): grouped by local day here
        $payments = CustomOrder::query()
            ->forShopper($shopper->id)
            ->where('payment_status', CustomOrder::PAYMENT_PAID)
            ->where('paid_at', '>=', $weekStart->copy()->subWeek()->utc())
            ->toBase()
            ->get(['paid_at', 'shopper_fees'])
            ->map(fn ($row) => ['at' => Carbon::parse($row->paid_at, 'UTC')->setTimezone($timezone), 'fee' => (float) $row->shopper_fees]);

        $sameStretchLastWeek = $now->copy()->subWeek();
        $lastWeek = $payments->filter(fn ($p) => $p['at']->lt($weekStart) && $p['at']->lte($sameStretchLastWeek))->sum('fee');
        $thisWeek = $payments->filter(fn ($p) => $p['at']->gte($weekStart))->values();

        $days = [];
        for ($day = $weekStart->copy(); $day->lte($weekEnd); $day->addDay()) {
            $days[] = [
                'date'   => $day->copy(),
                'amount' => round($thisWeek->filter(fn ($p) => $p['at']->isSameDay($day))->sum('fee'), 2),
            ];
        }

        $minutes = CustomOrder::query()
            ->forShopper($shopper->id)
            ->whereNotNull('started_at')
            ->whereNotNull('purchased_at')
            ->whereColumn('purchased_at', '>=', 'started_at')
            ->toBase()
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, purchased_at)) AS average')
            ->value('average');

        $profile = $shopper->shopperProfile()->with('categories')->first();

        return [
            'currency'                 => (string) config('custom_orders.currency', 'SAR'),
            'timezone'                 => $timezone,
            'week_start'               => $weekStart,
            'week_end'                 => $weekEnd,
            'week_earnings'            => round($thisWeek->sum('fee'), 2),
            'last_week_earnings'       => round($lastWeek, 2),
            'days'                     => $days,
            'counters'                 => $this->home->stats($shopper),
            'average_shopping_minutes' => $minutes !== null ? (int) round((float) $minutes) : null,
            'categories'               => $this->specialtyShares($profile?->categories ?? collect()),
            'rating_average'           => round((float) ($profile?->rating_avg ?? 0), 1),
            'rating_count'             => (int) ($profile?->rating_count ?? 0),
        ];
    }

    /**
     * The shopper's profile specialties sharing 100% equally (custom order
     * items carry no category, so there is nothing to weigh them by).
     *
     * @param  iterable<int, Category>  $categories
     * @return list<array{id: int, name: string, percentage: int}>
     */
    protected function specialtyShares(iterable $categories): array
    {
        $categories = collect($categories)->sortBy('id')->values();
        $count      = $categories->count();

        if ($count === 0) {
            return [];
        }

        $base      = intdiv(100, $count);
        $remainder = 100 - $base * $count;

        return $categories->map(fn (Category $category, int $i) => [
            'id'         => $category->id,
            'name'       => $category->getTranslation('name', app()->getLocale()),
            'percentage' => $base + ($i < $remainder ? 1 : 0),
        ])->all();
    }
}
