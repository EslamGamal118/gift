<?php

namespace App\Http\Resources\CustomOrder;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CustomOrderItem
 */
class CustomOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The parent resource attaches the order so no per-item query is needed
        $currency = $this->resource->relationLoaded('customOrder')
            ? $this->customOrder->currency
            : (string) config('custom_orders.currency', 'SAR');
        $total    = $this->expectedTotalRange();

        return [
            'id'           => $this->id,
            'product_name' => $this->product_name,
            'description'  => $this->description,
            'quantity'     => (int) $this->quantity,
            'expected_price' => [
                'min' => $this->expected_price_min !== null ? Money::format($this->expected_price_min, $currency) : null,
                'max' => $this->expected_price_max !== null ? Money::format($this->expected_price_max, $currency) : null,
            ],
            // What the shopper actually paid (null until the order is purchased)
            'unit_price'   => $this->unit_price !== null ? Money::format($this->unit_price, $currency) : null,
            'total_price'  => $this->unit_price !== null ? Money::format($this->totalPrice(), $currency) : null,
            'images'       => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id'            => $m->id,
                'url'           => $m->url(),
                'original_name' => $m->original_name,
            ])->values()),
            // Products the shopper suggested instead of this item (for review)
            'alternatives' => CustomOrderAlternativeResource::collection($this->whenLoaded('alternatives')),
            'sort_order'   => (int) $this->sort_order,
        ];
    }
}
