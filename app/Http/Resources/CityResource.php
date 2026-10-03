<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A serviced city the customer can select manually (config/cities.php).
 *
 * @mixin \App\Support\City
 */
class CityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key'       => $this->key,
            'name'      => $this->name(),
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'radius_km' => $this->radiusKm,
        ];
    }
}
