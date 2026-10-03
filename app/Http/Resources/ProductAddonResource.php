<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing add-on card as offered on the product details screen
 * (chocolates, greeting card, gift wrap, ...). Only what the customer needs
 * to pick it; merchant fields (activity flag, category links) are omitted.
 *
 * @mixin \App\Models\Addon
 */
class ProductAddonResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'price'    => Money::format($this->price),
            'image'    => $this->fileUrl($this->image),
            'in_stock' => $this->stock_quantity > 0,
            // Cap for the quantity stepper; the cart re-validates on add.
            'max_quantity' => (int) $this->stock_quantity,
        ];
    }
}
