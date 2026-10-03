<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The store card with distance and delivery flattened to three numbers
 * (`delivery_fee`, `delivery_time_minutes`, `distance_in_km`), 0 when they
 * could not be worked out (no customer position, no branch location ...).
 * Used by the home screen's `nearby_stores` and the category store listings;
 * other screens keep StoreListingResource's nested `distance` / `delivery`.
 */
class CompactStoreListingResource extends StoreListingResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = parent::toArray($request);

        return Arr::except($card, ['distance_km', 'distance', 'delivery', 'distance_in_km', 'delivery_fee', 'delivery_time_minutes', 'is_open']) + [
            'delivery_fee'          => (float) ($card['delivery_fee'] ?? 0),
            'delivery_time_minutes' => (int) ($card['delivery_time_minutes'] ?? 0),
            'distance_in_km'        => (float) ($card['distance_in_km'] ?? 0),
            'is_open'               => $card['is_open'],
        ];
    }
}
