<?php

namespace App\Http\Resources\Home;

use App\Http\Resources\CityResource;
use App\Support\CustomerLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The position the home screen was computed for, and how it was obtained.
 *
 * `is_approximate` is true when a city centroid stands in for the customer, in
 * which case the client should show the "enable location" prompt in `prompt`.
 *
 * @mixin CustomerLocation
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerLocation $location */
        $location = $this->resource;

        return [
            'latitude'       => $location->latitude,
            'longitude'      => $location->longitude,
            'source'         => $location->source,
        ];
    }
}
