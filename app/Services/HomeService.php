<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use App\Support\CustomerLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class HomeService
{
    public function __construct(
        protected CatalogService $catalog,
        protected FavoriteService $favorites,
        protected GiftService $gifts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?User $user, ?CustomerLocation $location, ?float $radiusKm = null): array
    {
        $onlineGiftsCategory = $this->onlineGiftsCategory();
        $radius              = $location ? ($radiusKm ?? $location->defaultRadiusKm()) : null;

        return [
            'user'              => $user,
            'notifications'     => ['unread_count' => $this->unreadNotifications($user)],
            // "You received a new gift" popup (signed-in customers only; never cached)
            'incoming_gift'     => $this->gifts->incomingFor($user),
            'banners'           => $this->banners(),
            'categories'        => $this->categories(),
            'custom_order'      => ['available' => $this->personalShoppersAvailable()],
            'featured_products' => $this->featuredProducts(),
            'online_gifts'      => $onlineGiftsCategory ? [
                'category' => $onlineGiftsCategory,
                'items'    => $this->onlineGifts($onlineGiftsCategory),
            ] : null,
            'nearby_stores'     => [
                'radius_km' => $radius,
                'items'     => $this->nearbyStores($location, $radius),
            ],
        ];
    }

    /**
     * @return Collection<int, Banner>
     */
    public function banners(): Collection
    {
        return $this->remember('banners', fn () => Banner::query()->active()->ordered()->get());
    }

    /**
     * Main horizontal category list (special categories get their own section).
     *
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return $this->remember('categories', fn () => Category::query()->active()->regular()->orderBy('id')->get());
    }

    /**
     * The special category that groups digital / online-only gifts.
     */
    public function onlineGiftsCategory(): ?Category
    {
        return $this->remember('online_gifts_category', fn () => Category::query()->active()->special()->orderBy('id')->first());
    }

    /**
     * Curated products; falls back to the best-rated ones when nothing is flagged.
     *
     * @return Collection<int, Product>
     */
    public function featuredProducts(): Collection
    {
        $limit = (int) config('stores.home.featured_products', 10);

        $featured = $this->productsQuery()->featured()->inStock()->orderByDesc('id')->limit($limit)->get();

        if ($featured->isNotEmpty()) {
            return $featured;
        }

        return $this->productsQuery()
            ->inStock()
            ->orderByDesc('rating_avg')
            ->orderByDesc('rating_count')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    public function onlineGifts(Category $category): Collection
    {
        return $this->productsQuery()
            ->inCategory($category->id)
            ->inStock()
            ->orderByDesc('is_featured')
            ->orderByDesc('rating_avg')
            ->orderByDesc('id')
            ->limit((int) config('stores.home.online_gifts', 10))
            ->get();
    }

    /**
     * Stores around the customer, nearest first. Without any position the
     * section degrades to the top-rated stores (no `distance_km`).
     *
     * @return Collection<int, StoreProfile>
     */
    public function nearbyStores(?CustomerLocation $location, ?float $radiusKm = null): Collection
    {
        $limit = (int) config('stores.home.nearby_limit', 10);

        if (! $location) {
            return $this->catalog->storesQuery(['sort' => 'rating'])->limit($limit)->get();
        }

        $filters = $radiusKm !== null ? ['within_km' => $radiusKm] : [];

        return $this->catalog->nearbyStoresQuery($location, $filters)->limit($limit)->get();
    }

    /**
     * Home product sections are rankings, so the viewer's favorites come first.
     */
    protected function productsQuery(): Builder
    {
        return Product::query()
            ->visible()
            ->notExpired()
            ->favoritesFirst($this->favorites->viewerId())
            ->with(['category', 'storeProfile']);
    }

    protected function unreadNotifications(?User $user): int
    {
        return $user ? $user->appNotifications()->unread()->count() : 0;
    }

    /**
     * Whether the "send your order" (personal shopper) banner should be active.
     */
    protected function personalShoppersAvailable(): bool
    {
        return $this->remember(
            'shoppers_available',
            fn () => ShopperProfile::query()->where('status', 'approved')->exists()
        );
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function remember(string $key, \Closure $callback): mixed
    {
        $ttl = (int) config('stores.home.cache_ttl', 0);

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember("home:{$key}", $ttl, $callback);
    }
}
