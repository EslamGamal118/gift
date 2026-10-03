<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Support\CustomerLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Customer-facing store screen: the visible store, the
 * product filter tabs, a paginated product list and the latest reviews.
 */
class StoreShowService
{
    public const TAB_BEST_SELLERS = 'best_sellers';
    public const TAB_ALL          = 'all';

    public const PRODUCT_SORTS = ['best_sellers', 'newest', 'price_asc', 'price_desc', 'rating', 'name'];

    public function __construct(
        protected FavoriteService $favorites,
    ) {
    }

    /**
     * The store with its category and, for a signed-in viewer, `is_favorite`
     * (one query plus the category), or 404 when it is not visible to customers.
     * With a location, `distance_km` (road distance to the nearest active
     * branch) is selected in the same query.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id, ?CustomerLocation $location = null): StoreProfile
    {
        return StoreProfile::query()
            ->visible()
            ->with('category')
            ->when($location, fn (Builder $q) => $q->withDistanceTo($location->latitude, $location->longitude))
            ->withFavoriteFlag($this->favorites->viewerId())
            ->findOrFail($id);
    }

    /**
     * Horizontal filter tabs: "best sellers", "all", then one tab per category
     * the store currently sells in (with its product count).
     *
     * @return array<int, array{key: string, category_id: int|null, name: string, image: string|null, count: int}>
     */
    public function tabs(StoreProfile $store): array
    {
        $counts = $this->productsQuery($store)
            ->toBase()
            ->selectRaw('category_id, COUNT(*) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $total = (int) $counts->sum();

        $tabs = [
            ['key' => self::TAB_BEST_SELLERS, 'category_id' => null, 'name' => __('store.tabs.best_sellers'), 'image' => null, 'count' => $total],
            ['key' => self::TAB_ALL,          'category_id' => null, 'name' => __('store.tabs.all'),          'image' => null, 'count' => $total],
        ];

        if ($counts->isEmpty()) {
            return $tabs;
        }

        Category::query()
            ->active()
            ->whereIn('id', $counts->keys())
            ->orderBy('id')
            ->get()
            ->each(function (Category $category) use (&$tabs, $counts) {
                $tabs[] = [
                    'key'         => (string) $category->id,
                    'category_id' => $category->id,
                    'name'        => $category->getTranslation('name', app()->getLocale()),
                    'image'       => $category->image,
                    'count'       => (int) $counts[$category->id],
                ];
            });

        return $tabs;
    }

    /**
     * Paginated products of the store for the selected tab.
     *
     * @param  array<string, mixed>  $filters  tab, search, in_stock, sort
     */
    public function products(StoreProfile $store, array $filters, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        $tab   = (string) ($filters['tab'] ?? self::TAB_BEST_SELLERS);
        $query = $this->productsQuery($store)->with('category');

        if (filled($filters['search'] ?? null)) {
            $query->search($filters['search']);
        }

        if (filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->inStock();
        }

        if (ctype_digit($tab)) {
            $query->inCategory((int) $tab);
        }

        $sort = $filters['sort'] ?? ($tab === self::TAB_BEST_SELLERS ? 'best_sellers' : 'newest');

        // Viewer's favorites first for ranking sorts; price / name orders stay exact.
        if ($boost = $this->favorites->boostFor($sort)) {
            $query->favoritesFirst($boost);
        } else {
            $query->withFavoriteFlag($this->favorites->viewerId());
        }

        match ($sort) {
            'best_sellers' => $query->bestSellers(),
            'price_asc'    => $query->orderBy('price')->orderByDesc('id'),
            'price_desc'   => $query->orderByDesc('price')->orderByDesc('id'),
            'rating'       => $query->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderByDesc('id'),
            'name'         => $query->orderBy('name')->orderByDesc('id'),
            default        => $query->orderByDesc('id'),
        };

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Latest visible reviews shown on the store screen.
     *
     * @return Collection<int, StoreReview>
     */
    public function recentReviews(StoreProfile $store, ?int $limit = null): Collection
    {
        return $this->reviewsQuery($store)
            ->limit($limit ?? (int) config('stores.show.recent_reviews', 5))
            ->get();
    }

    /**
     * All visible reviews, newest first, optionally for a single star value.
     */
    public function reviews(StoreProfile $store, ?int $rating, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        return $this->reviewsQuery($store)
            ->when($rating !== null, fn (Builder $q) => $q->where('rating', $rating))
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Average, count and the per-star distribution (5 stars first) for the rating bars.
     *
     * @return array{average: float, count: int, breakdown: array<int, array{stars: int, count: int, percentage: int}>}
     */
    public function ratingSummary(StoreProfile $store): array
    {
        $count     = (int) $store->rating_count;
        $breakdown = [];

        foreach (StoreReview::breakdownFor($store->id) as $stars => $total) {
            $breakdown[] = [
                'stars'      => $stars,
                'count'      => $total,
                'percentage' => $count > 0 ? (int) round($total / $count * 100) : 0,
            ];
        }

        return [
            'average'   => round((float) $store->rating_avg, 1),
            'count'     => $count,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Products a customer may see on this store: owned by the merchant account,
     * not expired. Store visibility itself is checked by find().
     */
    protected function productsQuery(StoreProfile $store): Builder
    {
        return Product::query()
            ->forStore($store->user_id)
            ->notExpired();
    }

    protected function reviewsQuery(StoreProfile $store): Builder
    {
        return StoreReview::query()
            ->forStore($store->id)
            ->visible()
            ->with('user:id,name,avatar')
            ->recent();
    }
}
