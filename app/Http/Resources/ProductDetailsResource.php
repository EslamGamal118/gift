<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFavoriteState;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Product details screen (GET /products/{id}).
 *
 * The product itself plus the sections the controller injects: delivery
 * metrics, add-ons, related products and recent reviews. Sections that
 * were not injected are omitted, so the resource can also be rendered on
 * its own (e.g. inside a cart line).
 *
 * @mixin \App\Models\Product
 */
class ProductDetailsResource extends JsonResource
{
    use ResolvesFavoriteState, ResolvesFileUrls;

    /**
     * @var array{delivery_fee: float, delivery_time_minutes: int, distance_in_km: float}|null
     */
    protected ?array $delivery = null;

    /**
     * @var Collection<int, \App\Models\Addon>|null
     */
    protected ?Collection $addons = null;

    /**
     * @var Collection<int, \App\Models\Product>|null
     */
    protected ?Collection $related = null;

    /**
     * @var Collection<int, \App\Models\StoreReview>|null
     */
    protected ?Collection $reviews = null;

    /**
     * @param  array{delivery_fee: float, delivery_time_minutes: int, distance_in_km: float}  $delivery  ProductShowService::deliveryMetrics()
     */
    public function withDelivery(array $delivery): static
    {
        $this->delivery = $delivery;

        return $this;
    }

    /**
     * @param  Collection<int, \App\Models\Addon>  $addons
     */
    public function withAddons(Collection $addons): static
    {
        $this->addons = $addons;

        return $this;
    }

    /**
     * @param  Collection<int, \App\Models\Product>  $related
     */
    public function withRelated(Collection $related): static
    {
        $this->related = $related;

        return $this;
    }

    /**
     * @param  Collection<int, \App\Models\StoreReview>  $reviews
     */
    public function withReviews(Collection $reviews): static
    {
        $this->reviews = $reviews;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = $this->whenLoaded('storeProfile', fn () => $this->storeProfile, null);

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'description'      => $this->description,
            'price'            => [
                'amount'   => round((float) $this->price, 2),
                'currency' => strtoupper((string) config('stores.delivery.currency', 'SAR')),
            ],
            'image'            => $this->fileUrl($this->image),
            'in_stock'         => $this->isInStock(),
            // Also the cap for the quantity stepper; the cart re-validates on add.
            'stock_quantity'   => (int) $this->stock_quantity,
            'expiry_date'      => $this->expiry_date?->toDateString(),
            'is_featured'      => (bool) $this->is_featured,
            'is_favorite'      => $this->isFavorite(),
            'rating'           => [
                'average' => round((float) $this->rating_avg, 1),
                'count'   => (int) $this->rating_count,
            ],
            'store'            => $store ? new ProductStoreResource($store) : null,

            $this->mergeWhen($this->delivery !== null, fn () => $this->delivery),

            'addons'           => $this->when($this->addons !== null, fn () => [
                'title' => __('store.product.addons_title'),
                'items' => ProductAddonResource::collection($this->addons),
            ]),

            'related_products' => $this->when($this->related !== null, fn () => [
                'title' => __('store.product.related_title'),
                'items' => ProductListingResource::collection($this->related),
            ]),

            'reviews'          => $this->when($this->reviews !== null, fn () => [
                'total'   => (int) ($store?->rating_count ?? 0),
                'average' => round((float) ($store?->rating_avg ?? 0), 1),
                'items'   => StoreReviewResource::collection($this->reviews),
            ]),
        ];
    }
}
