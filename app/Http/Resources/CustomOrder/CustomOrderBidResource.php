<?php

namespace App\Http\Resources\CustomOrder;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shopper's offer on a custom order opened for bidding.
 *
 * @mixin \App\Models\CustomOrderBid
 */
class CustomOrderBidResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->whenLoaded('shopperProfile', fn () => $this->shopperProfile, null);

        return [
            'id'           => $this->id,
            'shopper'      => $profile
                ? new PersonalShopperResource($profile->setRelation('user', $this->shopper))
                : ['id' => $this->shopper_id, 'name' => $this->whenLoaded('shopper', fn () => $this->shopper?->name)],
            'amount'       => Money::format($this->amount),
            'service_fee'  => Money::format($this->service_fee),
            'delivery_at'  => $this->delivery_at?->toIso8601String(),
            'message'      => $this->message,
            'status'       => $this->status,
            'status_label' => __('custom_orders.bid_statuses.'.$this->status),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
