<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'order_type'   => $this->reviewable_type,
            'order_id'     => $this->reviewable_id,
            'order_number' => $this->whenLoaded('reviewable', fn () => $this->reviewable?->order_number),
            'store'        => [
                'rating'  => $this->store_rating,
                'comment' => $this->store_comment,
            ],
            'products'     => [
                'rating'  => $this->products_rating,
                'comment' => $this->products_comment,
            ],
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
