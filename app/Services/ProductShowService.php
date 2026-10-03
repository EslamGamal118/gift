<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Support\CustomerLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Customer-facing product details screen: the purchasable product with its
 * store (and distance to it), the delivery estimate, optional add-ons,
 * related products and the latest store reviews.
 */
class ProductShowService
{
    public function __construct(
        protected StoreShowService $stores,
        protected DeliveryCalculatorService $delivery,
    ) {
    }

    /**
     * The product a customer may buy right now, or 404. The owning store is
     * attached as `storeProfile` with `distance_km` (to its nearest active
     * branch) when a location is given.
     *
     * Not found when the product is expired, out of stock, or its store is
     * not approved / its owner account is not active.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id, ?CustomerLocation $location = null): Product
    {
        $product = Product::query()
            ->visible()
            ->notExpired()
            ->inStock()
            ->findOrFail($id);

        $product->setRelation('storeProfile', $this->storeFor($product, $location));

        return $product;
    }

    /**
     * Distance from the customer to the product's store, in km (null without a
     * location or when the store has no located branch).
     */
    public function distanceKm(Product $product): ?float
    {
        $distance = $product->storeProfile?->getAttribute('distance_km');

        return $distance !== null ? (float) $distance : null;
    }

    /**
     * Delivery fee, earliest delivery time (using the product's own
     * preparation time when it has one) and distance to the store. Never
     * fails: without a position the distance is 0 and fee / time fall back to
     * the base fee and preparation time; anything that cannot be computed is 0.
     *
     * @return array{delivery_fee: float, delivery_time_minutes: int, distance_in_km: float}
     */
    public function deliveryMetrics(Product $product): array
    {
        $distance = $this->distanceKm($product);

        try {
            $store       = $product->storeProfile;
            $preparation = $product->preparation_time > 0 ? (int) $product->preparation_time : null;
            $fee         = $this->delivery->fee($store, $distance);
            $minutes     = $this->delivery->time($store, $distance, $preparation)['min'];
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'delivery_fee'          => (float) ($fee ?? 0),
            'delivery_time_minutes' => (int) ($minutes ?? 0),
            'distance_in_km'        => $distance ?? 0.0,
        ];
    }

    /**
     * Optional extras the customer can attach before adding to the cart: the
     * store's active, in-stock add-ons linked to the product's category.
     *
     * @return Collection<int, Addon>
     */
    public function addons(Product $product, ?int $limit = null): Collection
    {
        return $product->availableAddons()
            ->inStock()
            ->orderBy('price')
            ->orderBy('name')
            ->limit($limit ?? (int) config('stores.product.addons_limit', 20))
            ->get();
    }

    /**
     * "You may also like": other purchasable products of the same store,
     * same category first, best rated first.
     *
     * @return Collection<int, Product>
     */
    public function relatedProducts(Product $product, ?int $limit = null): Collection
    {
        return Product::query()
            ->forStore($product->store_id)
            ->whereKeyNot($product->id)
            ->notExpired()
            ->inStock()
            ->with('category')
            ->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$product->category_id])
            ->orderByDesc('rating_avg')
            ->orderByDesc('rating_count')
            ->orderByDesc('id')
            ->limit($limit ?? (int) config('stores.product.related_limit', 8))
            ->get();
    }

    /**
     * Latest visible reviews of the product's store.
     *
     * @return Collection<int, StoreReview>
     */
    public function recentReviews(Product $product, ?int $limit = null): Collection
    {
        return $this->stores->recentReviews(
            $product->storeProfile,
            $limit ?? (int) config('stores.product.recent_reviews', 3),
        );
    }

    /**
     * The visible store profile that owns the product, with `distance_km`
     * when a location is given.
     *
     * @throws ModelNotFoundException
     */
    protected function storeFor(Product $product, ?CustomerLocation $location): StoreProfile
    {
        return StoreProfile::query()
            ->visible()
            ->when($location, fn (Builder $q) => $q->withDistanceTo($location->latitude, $location->longitude))
            ->where('store_profiles.user_id', $product->store_id)
            ->firstOrFail();
    }
}
