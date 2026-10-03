<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Review;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The "Reviews" screen of each account type, always for the signed-in user:
 *
 *  - customer: the reviews they gave (store orders rate the store, custom
 *    orders rate the personal shopper)          -> `reviews.user_id`
 *  - store:    the reviews its customers left   -> visible `store_reviews`
 *  - shopper:  the reviews of their custom orders -> `reviews` on custom_orders.shopper_id
 *
 * Each returns the same thing: a summary (average, count, per-star breakdown
 * of ALL the subject's reviews) and one page of reviews narrowed by the
 * filters (stars, with_comment, sort).
 */
class ReviewFeedService
{
    public const SORTS = ['newest', 'highest', 'lowest'];

    /**
     * @param  array{stars?: ?int, with_comment?: bool, sort?: string}  $filters
     * @return array{summary: array<string, mixed>, reviews: LengthAwarePaginator}
     */
    public function given(User $customer, array $filters, int $perPage): array
    {
        $query = Review::query()
            ->where('user_id', $customer->id)
            ->when(($filters['subject'] ?? 'all') !== 'all', fn (Builder $q) => $q->where(
                'reviewable_type',
                $filters['subject'] === 'shopper' ? Review::TYPE_CUSTOM_ORDER : Review::TYPE_ORDER,
            ));

        return [
            'summary' => $this->summaryOf((clone $query), 'store_rating'),
            'reviews' => $this->page(
                $query->with(['reviewable' => fn (MorphTo $morph) => $morph->morphWith([
                    Order::class => ['storeProfile'],
                    CustomOrder::class => ['shopper'],
                ])]),
                'store_rating', 'store_comment', $filters, $perPage,
            ),
        ];
    }

    /**
     * @param  array{stars?: ?int, with_comment?: bool, sort?: string}  $filters
     * @return array{summary: array<string, mixed>, reviews: LengthAwarePaginator}
     */
    public function forStore(User $store, array $filters, int $perPage): array
    {
        $profileId = (int) $store->storeProfile?->id;
        $query = StoreReview::query()->forStore($profileId)->visible();

        // The aggregate kept on the profile by StoreReview, plus its star counts
        $summary = $this->summary(
            (float) $store->storeProfile?->rating_avg,
            (int) $store->storeProfile?->rating_count,
            StoreReview::breakdownFor($profileId),
        );

        return [
            'summary' => $summary,
            'reviews' => $this->page($query->with(['user:id,name,avatar', 'order:id,order_number']), 'rating', 'comment', $filters, $perPage),
        ];
    }

    /**
     * @param  array{stars?: ?int, with_comment?: bool, sort?: string}  $filters
     * @return array{summary: array<string, mixed>, reviews: LengthAwarePaginator}
     */
    public function forShopper(User $shopper, array $filters, int $perPage): array
    {
        $query = Review::query()
            ->where('reviewable_type', Review::TYPE_CUSTOM_ORDER)
            ->whereIn('reviewable_id', CustomOrder::query()->where('shopper_id', $shopper->id)->select('id'));

        return [
            'summary' => $this->summaryOf((clone $query), 'store_rating'),
            'reviews' => $this->page($query->with(['user:id,name,avatar', 'reviewable']), 'store_rating', 'store_comment', $filters, $perPage),
        ];
    }

    /**
     * @param  array{stars?: ?int, with_comment?: bool, sort?: string}  $filters
     */
    protected function page(Builder $query, string $ratingColumn, string $commentColumn, array $filters, int $perPage): LengthAwarePaginator
    {
        $query
            ->when($filters['stars'] ?? null, fn (Builder $q, int $stars) => $q->where($ratingColumn, $stars))
            ->when($filters['with_comment'] ?? false, fn (Builder $q) => $q->whereNotNull($commentColumn)->where($commentColumn, '!=', ''));

        match ($filters['sort'] ?? 'newest') {
            'highest' => $query->orderByDesc($ratingColumn)->orderByDesc('created_at'),
            'lowest' => $query->orderBy($ratingColumn)->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        // id breaks ties so pages are stable
        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Average, count and per-star counts of a reviews query, in one grouped query.
     *
     * @return array<string, mixed>
     */
    protected function summaryOf(Builder $query, string $ratingColumn): array
    {
        $counts = $query->toBase()
            ->selectRaw("{$ratingColumn} AS stars, COUNT(*) AS total")
            ->groupBy($ratingColumn)
            ->pluck('total', 'stars')
            ->map(fn ($total) => (int) $total);

        $total = $counts->sum();
        $average = $total > 0 ? $counts->map(fn (int $count, $stars) => $count * (int) $stars)->sum() / $total : 0.0;

        $breakdown = [];
        for ($stars = Review::MAX_RATING; $stars >= Review::MIN_RATING; $stars--) {
            $breakdown[$stars] = (int) ($counts[$stars] ?? 0);
        }

        return $this->summary($average, $total, $breakdown);
    }

    /**
     * @param  array<int, int>  $breakdown  stars => count, 5 first
     * @return array{average_rating: float, total_reviews: int, breakdown: list<array{stars: int, count: int, percentage: int}>}
     */
    protected function summary(float $average, int $total, array $breakdown): array
    {
        $percentages = $this->percentages($breakdown);

        return [
            'average_rating' => round($average, 1),
            'total_reviews' => $total,
            'breakdown' => array_map(fn (int $stars) => [
                'stars' => $stars,
                'count' => $breakdown[$stars],
                'percentage' => $percentages[$stars],
            ], array_keys($breakdown)),
        ];
    }

    /**
     * Whole percents of each count summing to 100 (largest remainder); all 0 without reviews.
     *
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    protected function percentages(array $counts): array
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return array_map(fn () => 0, $counts);
        }

        $exact = array_map(fn (int $count) => $count * 100 / $total, $counts);
        $shares = array_map(fn (float $value) => (int) floor($value), $exact);
        $remainders = array_map(fn (float $value, int $floor) => $value - $floor, $exact, $shares);
        $keys = array_keys($counts);
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($shares)) as $index) {
            $shares[$keys[$index]]++;
        }

        return $shares;
    }
}
