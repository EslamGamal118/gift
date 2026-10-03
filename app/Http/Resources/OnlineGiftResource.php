<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Product card for the "online gifts" (special category) home section:
 * a regular product card plus the number of gifting slots still available.
 *
 * @mixin \App\Models\Product
 */
class OnlineGiftResource extends ProductListingResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slots = max(0, (int) $this->stock_quantity);

        return parent::toArray($request) + [
            'is_digital'      => true,
            'available_slots' => $slots,
            'slots_label'     => trans_choice('home.slots_available', $slots),
        ];
    }
}
