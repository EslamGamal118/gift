<?php

namespace App\Http\Resources\Checkout;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->product_name,
            'image' => $this->fileUrl($this->product_image),
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
            'addons' => $this->whenLoaded('addons', fn () => $this->addons->map(fn ($a) => [
                'id' => $a->id,
                'addon_id' => $a->addon_id,
                'name' => $a->name,
                'unit_price' => (float) $a->unit_price,
                'quantity' => $a->quantity,
                'subtotal' => (float) $a->subtotal,
            ])->all()),
            'addons_total' => (float) $this->addons_total,
            'subtotal' => (float) $this->subtotal,
        ];
    }
}
