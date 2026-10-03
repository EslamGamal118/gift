<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFavoriteState;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Services\DeliveryCalculatorService;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing store card: rating, distance, delivery estimate and open state.
 * `distance_km` / `distance` are non-null only when the query was built with
 * StoreProfile::withDistanceTo() (or nearby()); the distance is the road
 * distance to the store's nearest branch, and the delivery fee / time are
 * priced from it by DeliveryCalculatorService.
 *
 * @mixin \App\Models\StoreProfile
 */
class StoreListingResource extends JsonResource
{
    use ResolvesFavoriteState, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $distance = $this->resource->getAttribute('distance_km');
        $distance = $distance !== null ? (float) $distance : null;
        $quote    = app(DeliveryCalculatorService::class)->quote($this->resource, $distance);

        return [
            'id'          => $this->id,
            'user_id'     => $this->user_id,
            'name'        => $this->store_name,
            'description' => $this->description,
            'logo'        => $this->fileUrl($this->logo),
            'cover_image' => $this->fileUrl($this->cover_image),
            'category'    => new CategoryResource($this->whenLoaded('category')),
            'rating'      => [
                'average' => round((float) $this->rating_avg, 1),
            ],
            'is_featured' => (bool) $this->is_featured,
            'is_favorite' => $this->isFavorite(),
            'distance_km' => $distance,
            // Flat shortcuts of the values below, for list screens
            'distance_in_km'        => $distance,
            'delivery_fee'          => $quote['fee'],
            'delivery_time_minutes' => $quote['time']['min'],
            'distance'    => $distance !== null ? [
                'km'    => $distance,
                'label' => Geo::formatKm($distance),
            ] : null,
            'delivery'    => [
                'fee'          => $quote['fee'],
                'currency'     => $quote['currency'],
                'time'         => [
                    'min'   => $quote['time']['min'],
                    'max'   => $quote['time']['max'],
                    'label' => __('search.delivery_time', $quote['time']),
                ],
                'available'    => $quote['available'],
                'is_estimated' => $quote['is_estimated'],
            ],
            'is_open'     => $this->isOpenNow(),
        ];
    }
}
