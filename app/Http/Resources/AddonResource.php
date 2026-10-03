<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Addon
 */
class AddonResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'price'          => (float) $this->price,
            'stock_quantity' => $this->stock_quantity,
            'in_stock'       => $this->stock_quantity > 0,
            'image'          => $this->fileUrl($this->image),
            'is_active'      => $this->is_active,
            'categories'     => CategoryResource::collection($this->whenLoaded('categories')),
            'category_ids'   => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->all()),
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
