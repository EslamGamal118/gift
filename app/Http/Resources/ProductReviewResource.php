<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A customer's rating of the products of an order, as shown on the product
 * reviews screen. Same shape as a store review; the reviewer's name is
 * shortened to "First L." the same way.
 *
 * @mixin \App\Models\Review
 */
class ProductReviewResource extends StoreReviewResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->whenLoaded('user', fn () => $this->user, null);

        return [
            'id'         => $this->id,
            'rating'     => (int) $this->products_rating,
            'comment'    => $this->products_comment,
            'user'       => [
                'id'     => $this->user_id,
                'name'   => $this->displayName($user?->name),
                'avatar' => $this->fileUrl($user?->avatar),
            ],
            'is_verified_purchase' => true,   // every review comes from a delivered order
            'created_at'           => $this->created_at?->toIso8601String(),
            'created_at_human'     => $this->created_at?->diffForHumans(),
        ];
    }
}
