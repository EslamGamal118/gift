<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFavoriteState;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing product card with a store summary.
 *
 * @mixin \App\Models\Product
 */
class ProductListingResource extends JsonResource
{
    use ResolvesFavoriteState, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = $this->whenLoaded('storeProfile', fn () => $this->storeProfile, null);

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'price'            => (float) $this->price,
            'currency'         => config('stores.delivery.currency', 'SAR'),
            'image'            => $this->fileUrl($this->image),
            'in_stock'         => $this->isInStock(),
            'is_featured'      => (bool) $this->is_featured,
            'is_favorite'      => $this->isFavorite(),
            'stock_quantity'   => $this->stock_quantity,
            'rating'           => [
                'average' => round((float) $this->rating_avg, 1),
                'count'   => (int) $this->rating_count,
            ],
            'store'            => $store ? [
                'id'      => $store->id,
                'user_id' => $store->user_id,
                'name'    => $store->store_name,
                'logo'    => $this->fileUrl($store->logo),
            ] : null,
        ];
    }
}
