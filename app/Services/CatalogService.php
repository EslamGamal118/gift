<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Support\CustomerLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CatalogService
{
    public const STORE_SORTS   = ['nearest', 'rating', 'newest', 'name'];
    public const PRODUCT_SORTS = ['newest', 'price_asc', 'price_desc', 'name'];

    /** The only `sort` values of the category listings (stores and products). */
    public const LISTING_SORTS = ['popular', 'most_popular', 'price_asc', 'price_desc', 'rating_desc', 'top_rated'];

    /** Alternative spellings of a sort, mapped to the canonical key. */
    public const SORT_ALIASES = ['most_popular' => 'popular', 'top_rated' => 'rating_desc'];

    public function __construct(
        protected FavoriteService $favorites,
    ) {
    }

    /**
     * Visible stores with their distance to the customer (when coordinates are given).
     *
     * @param  array<string, mixed>  $filters
     */
    public function storesQuery(array $filters = []): Builder
    {
        $hasLocation = isset($filters['latitude'], $filters['longitude']);
        $categoryIds = $this->categoryIds($filters);

        $query = StoreProfile::query()
            ->visible()
            ->with(['category', 'mainBranch', 'user:id,status'])
            ->when($categoryIds !== [], fn (Builder $q) => $q->offeringCategory($categoryIds))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->searchName($filters['search']))
            ->when(isset($filters['min_rating']), fn (Builder $q) => $q->minRating((float) $filters['min_rating']))
            ->when($hasLocation, function (Builder $q) use ($filters) {
                $q->withDistanceTo((float) $filters['latitude'], (float) $filters['longitude']);

                if (isset($filters['within_km'])) {
                    // Index-backed rectangle first, exact road-distance radius second.
                    $q->withinBoundingBox((float) $filters['latitude'], (float) $filters['longitude'], (float) $filters['within_km'])
                        ->withinKm((float) $filters['within_km']);
                }
            });

        $sort = $this->canonicalSort($filters['sort'] ?? ($hasLocation ? 'nearest' : 'rating'));

        $this->applyFavorites($query, $sort);

        // Ranking tiers: nearest -> highest rated -> best selling, then id so
        // ties keep a stable order across pages.
        match ($sort) {
            'nearest'    => $hasLocation
                ? $query->withSalesCount()
                    ->orderByRaw('distance_km IS NULL')->orderBy('distance_km')
                    ->orderByDesc('rating_avg')->orderByDesc('sales_count')->orderByDesc('store_profiles.id')
                : $this->orderByRating($query),
            'popular'    => $query->withSalesCount()
                ->orderByDesc('sales_count')->orderByDesc('rating_avg')->orderByDesc('store_profiles.id'),
            // By the store's cheapest product in the selected categories; stores without one last.
            'price_asc', 'price_desc' => $query->withStartingPrice($categoryIds)
                ->orderByRaw('starting_price IS NULL')
                ->orderBy('starting_price', $sort === 'price_asc' ? 'asc' : 'desc')
                ->orderByDesc('rating_avg')->orderByDesc('store_profiles.id'),
            'newest'     => $query->orderByDesc('store_profiles.id'),
            'name'       => $query->orderBy('store_name'),
            default      => $this->orderByRating($query), // rating, rating_desc
        };

        return $query;
    }

    protected function orderByRating(Builder $query): Builder
    {
        return $query->withSalesCount()
            ->orderByDesc('rating_avg')->orderByDesc('sales_count')
            ->orderByDesc('rating_count')->orderByDesc('store_profiles.id');
    }

    /**
     * Paginated stores. `open_now` depends on the weekly schedule and is evaluated in PHP,
     * so that filter paginates the filtered collection instead of the SQL result.
     *
     * @param  array<string, mixed>  $filters
     */
    public function stores(array $filters, int $perPage, ?int $page = null): PaginatorContract
    {
        $query = $this->storesQuery($filters);

        if (! filter_var($filters['open_now'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $query->paginate($perPage, ['*'], 'page', $page);
        }

        $open = $query->get()->filter(fn (StoreProfile $store) => $store->isOpenNow())->values();

        return $this->paginateCollection($open, $perPage, $page);
    }

    /**
     * Stores around the customer, nearest first, limited to a radius.
     * `within_km` in the filters overrides the location's default radius.
     *
     * @param  array<string, mixed>  $filters  category_id, search, min_rating, open_now, within_km
     */
    public function nearbyStoresQuery(CustomerLocation $location, array $filters = []): Builder
    {
        return $this->storesQuery($this->nearbyFilters($location, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function nearbyStores(CustomerLocation $location, array $filters, int $perPage, ?int $page = null): PaginatorContract
    {
        return $this->stores($this->nearbyFilters($location, $filters), $perPage, $page);
    }

    /**
     * Every store of a category, ranked nearest -> highest rated -> best selling.
     * Unlike nearbyStores() there is no default radius: stores beyond it, and
     * stores without a located branch, follow on later pages. An explicit
     * `within_km` still cuts the list.
     *
     * @param  array<string, mixed>  $filters
     */
    public function categoryStores(?CustomerLocation $location, array $filters, int $perPage, ?int $page = null): PaginatorContract
    {
        if ($location) {
            unset($filters['latitude'], $filters['longitude']);
            $filters = $location->coordinates() + $filters + ['sort' => 'nearest'];
        }

        return $this->stores($filters, $perPage, $page);
    }

    /**
     * Radius actually applied for a nearby query.
     *
     * @param  array<string, mixed>  $filters
     */
    public function nearbyRadiusKm(CustomerLocation $location, array $filters = []): float
    {
        return isset($filters['within_km']) ? (float) $filters['within_km'] : $location->defaultRadiusKm();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function nearbyFilters(CustomerLocation $location, array $filters): array
    {
        unset($filters['latitude'], $filters['longitude']);

        return $filters + $location->coordinates() + [
            'within_km' => $this->nearbyRadiusKm($location, $filters),
            'sort'      => 'nearest',
        ];
    }

    /**
     * Visible products with their store and category.
     *
     * @param  array<string, mixed>  $filters
     */
    public function productsQuery(array $filters = []): Builder
    {
        $categoryIds = $this->categoryIds($filters);

        $query = Product::query()
            ->visible()
            ->with(['category', 'storeProfile'])
            ->when($categoryIds !== [], fn (Builder $q) => $q->inCategories($categoryIds))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->search($filters['search']))
            ->when(filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->inStock())
            ->when(
                isset($filters['price_min']) || isset($filters['price_max']),
                fn (Builder $q) => $q->priceBetween(
                    isset($filters['price_min']) ? (float) $filters['price_min'] : null,
                    isset($filters['price_max']) ? (float) $filters['price_max'] : null,
                )
            )
            ->notExpired();

        $sort = $this->canonicalSort($filters['sort'] ?? 'newest');

        $this->applyFavorites($query, $sort);

        match ($sort) {
            'popular'     => $query->bestSellers()->orderByDesc('products.id'),
            'rating_desc' => $query->orderByDesc('products.rating_avg')->orderByDesc('products.rating_count')->orderByDesc('products.id'),
            'price_asc'   => $query->orderBy('price')->orderByDesc('products.id'),
            'price_desc'  => $query->orderByDesc('price')->orderByDesc('products.id'),
            'name'        => $query->orderBy('name')->orderByDesc('products.id'),
            default       => $query->orderByDesc('products.id'),
        };

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function products(array $filters, int $perPage, ?int $page = null): PaginatorContract
    {
        return $this->productsQuery($filters)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Active categories matching a keyword in any locale.
     */
    public function categoriesQuery(string $term): Builder
    {
        return Category::query()
            ->active()
            ->search($term)
            ->orderBy('id');
    }

    /**
     * Number of visible stores and products in a category (for the tab badges).
     *
     * @return array{stores: int, products: int}
     */
    public function categoryCounts(Category $category): array
    {
        return [
            'stores'   => StoreProfile::query()->visible()->offeringCategory($category->id)->count(),
            'products' => Product::query()->visible()->inCategory($category->id)->notExpired()->count(),
        ];
    }

    /**
     * Flag the viewer's favorites (`is_favorite`) and, for ranking sorts
     * (nearest / rating / newest), list them first. The regular ordering then
     * applies within each group, and radius / pagination stay in SQL.
     */
    protected function applyFavorites(Builder $query, string $sort): void
    {
        if ($boost = $this->favorites->boostFor($sort)) {
            $query->favoritesFirst($boost);
        } else {
            $query->withFavoriteFlag($this->favorites->viewerId());
        }
    }

    protected function canonicalSort(string $sort): string
    {
        return self::SORT_ALIASES[$sort] ?? $sort;
    }

    /**
     * Category filter as a list: `category_ids` (any of them), or the single `category_id`.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    protected function categoryIds(array $filters): array
    {
        $ids = $filters['category_ids'] ?? (isset($filters['category_id']) ? [$filters['category_id']] : []);

        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    protected function paginateCollection(Collection $items, int $perPage, ?int $page = null): PaginatorContract
    {
        $page ??= Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }
}
