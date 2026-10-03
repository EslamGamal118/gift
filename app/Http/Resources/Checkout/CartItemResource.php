<?php

namespace App\Http\Resources\Checkout;

use App\Http\Resources\AddonResource;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CartItem
 */
class CartItemResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;

        return [
            'id' => $this->id,
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'image' => $this->fileUrl($product->image),
                'price' => (float) $product->price,
                'stock_quantity' => $product->stock_quantity,
                'in_stock' => $product->isInStock(),
            ] : null,
            // The store the line is bought from, shown under the product card
            'store' => $this->when($product?->relationLoaded('storeProfile'), fn () => [
                'id' => (int) $product->store_id,
                'store_name' => $product->storeProfile?->store_name,
                'logo' => $this->fileUrl($product->storeProfile?->logo),
            ]),
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice(),
            'addons' => AddonResource::collection($this->whenLoaded('addons')),
            'addons_total' => $this->addonsTotal(),
            'line_total' => $this->lineTotal(),
        ];
    }
}
