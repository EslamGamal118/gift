<?php

namespace App\Http\Resources\Checkout;

use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserAddress
 */
class UserAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_name' => $this->location_name,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'building_number' => $this->building_number,
            'phone' => $this->phone,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'is_default' => $this->is_default,
            'full_address' => $this->toLine(),
        ];
    }
}
