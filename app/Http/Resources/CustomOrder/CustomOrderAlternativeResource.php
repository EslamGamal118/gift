<?php

namespace App\Http\Resources\CustomOrder;

use App\Models\CustomOrderAlternative;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product the shopper suggested instead of an unavailable item, as both the
 * shopper (after suggesting) and the customer (to review it) see it.
 *
 * @mixin CustomOrderAlternative
 */
class CustomOrderAlternativeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'item_id'      => $this->custom_order_item_id,
            'item_name'    => $this->whenLoaded('item', fn () => $this->item->product_name),
            'product_name' => $this->product_name,
            'price'        => Money::format($this->price, $this->currency),
            'reason'       => $this->reason,
            'image'        => $this->imageUrl(),
            'status'       => $this->status,
            // The order's status after the suggestion (waiting_for_alternative)
            'order'        => $this->whenLoaded('customOrder', fn () => [
                'id'           => $this->customOrder->id,
                'order_number' => $this->customOrder->order_number,
                'status'       => $this->customOrder->status,
                'status_label' => __('custom_orders.statuses.'.$this->customOrder->status),
            ]),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
