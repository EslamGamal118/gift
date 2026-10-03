<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant-facing product representation.
 *
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'category_id'      => $this->category_id,
            'category'         => new CategoryResource($this->whenLoaded('category')),
            'name'             => $this->name,
            'description'      => $this->description,
            'price'            => (float) $this->price,
            'stock_quantity'   => $this->stock_quantity,
            'in_stock'         => $this->isInStock(),
            'preparation_time' => $this->preparation_time,
            'expiry_date'      => $this->expiry_date?->toDateString(),
            'is_expired'       => $this->isExpired(),
            'image'            => $this->fileUrl($this->image),
            'rating'           => [
                'average' => round((float) $this->rating_avg, 1),
                'count'   => (int) $this->rating_count,
            ],
            // Populated on demand via Product::availableAddons() (see ProductController::show).
            'addons'           => AddonResource::collection($this->whenLoaded('addons')),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
