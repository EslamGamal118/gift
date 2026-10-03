<?php

namespace App\Http\Resources\CustomOrder;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public card of a personal shopper as shown to customers (step 2 and on the
 * order). Documents, IBAN and other private profile fields are never exposed.
 *
 * `id` is the shopper's *account* id - the value to send as `shopper_id`.
 * `distance` is present only when the listing query added `distance_km`;
 * `completed_orders_count` only when it added that aggregate.
 *
 * @mixin \App\Models\ShopperProfile
 */
class PersonalShopperResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user        = $this->whenLoaded('user', fn () => $this->user, null);
        $attributes  = $this->resource->getAttributes();
        $hasDistance = array_key_exists('distance_km', $attributes);
        $distance    = $hasDistance && $attributes['distance_km'] !== null ? (float) $attributes['distance_km'] : null;
        $completed   = $attributes['completed_orders_count'] ?? null;

        return [
            'id'         => $this->user_id,
            'profile_id' => $this->id,
            'name'       => $user?->name,
            'photo'      => $this->fileUrl($this->personal_photo ?: $user?->avatar),
            'bio'        => $this->bio,
            'rating'     => [
                'average' => round((float) $this->rating_avg, 1),
                'count'   => (int) $this->rating_count,
            ],
            'completed_orders_count' => $this->when($completed !== null, fn () => (int) $completed),
            'completed_orders_label' => $this->when($completed !== null, fn () => trans_choice('custom_orders.shopper.completed_orders', (int) $completed, ['count' => (int) $completed])),
            'specialties'  => CategoryResource::collection($this->whenLoaded('categories')),
            'is_available' => (bool) $this->is_available,
            'availability' => [
                'status' => $this->is_available ? 'available' : 'unavailable',
                'label'  => __('custom_orders.shopper.'.($this->is_available ? 'available' : 'unavailable')),
            ],
            'location'   => [
                'address'   => $this->address,
                'latitude'  => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],
            'distance'   => $this->when($hasDistance, fn () => $distance !== null ? [
                'km'    => $distance,
                'label' => Geo::formatKm($distance),
            ] : null),
        ];
    }
}
