<?php

namespace App\Http\Resources\Checkout;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // One flat list; each line carries its own store
            'items' => $this->whenLoaded('items', fn () => CartItemResource::collection($this->items->sortBy('id')->values())),
            'items_count' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),
            'cart_subtotal' => $this->whenLoaded('items', fn () => $this->subtotal()),
            'address' => new UserAddressResource($this->whenLoaded('address')),
            // null until the customer picks a delivery option
            'delivery' => $this->delivery_type ? [
                'type' => $this->delivery_type,
                'date' => $this->delivery_date?->toDateString(),
                'slot' => new DeliverySlotResource($this->whenLoaded('deliverySlot')),
            ] : null,
            'promo_code' => $this->whenLoaded('promoCode', fn () => $this->promoCode?->code),
            'gift_message' => $this->gift_message,
        ];
    }
}
