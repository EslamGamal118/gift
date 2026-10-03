<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Online gift details / payment screen, built from GiftService::details():
 * the store, the gift, its greeting cards (add-ons), the current selection
 * and the financial summary that POST /gifts/checkout will charge.
 *
 * @property array<string, mixed> $resource
 */
class GiftDetailsResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Product $product */
        $product  = $this->resource['product'];
        $store    = $product->storeProfile;
        $totals   = $this->resource['totals'];
        $currency = $totals['currency'];
        $selected = $this->resource['selected_addon_ids'];
        $promo    = $this->resource['promo'];

        return [
            'store' => $store ? [
                'id'          => $store->id,
                'user_id'     => $product->store_id,
                'name'        => $store->store_name,
                'logo'        => $this->fileUrl($store->logo),
                'rating'      => [
                    'average' => round((float) $store->rating_avg, 1),
                    'count'   => (int) $store->rating_count,
                ],
                'category'    => $store->category ? new CategoryResource($store->category) : null,
            ] : null,

            'gift' => [
                'id'             => $product->id,
                'name'           => $product->name,
                'description'    => $product->description,
                'image'          => $this->fileUrl($product->image),
                'price'          => Money::format($product->price, $currency),
                'category'       => $product->category ? new CategoryResource($product->category) : null,
                'is_digital'     => true,
                'in_stock'       => $product->stock_quantity > 0,
                // Cap for the quantity stepper; checkout re-validates the stock.
                'max_quantity'   => min((int) $product->stock_quantity, (int) config('gifts.max_quantity', 10)),
            ],

            // Greeting cards, wrapping... the customer can attach
            'cards' => collect($this->resource['addons'])->map(fn ($addon) => (new ProductAddonResource($addon))->toArray($request) + [
                'is_selected' => in_array($addon->id, $selected, true),
            ])->values(),

            'selection' => [
                'quantity'   => $this->resource['quantity'],
                'addon_ids'  => $selected,
                'promo_code' => $promo?->code,
            ],

            'promo' => $promo ? [
                'code'     => $promo->code,
                'type'     => $promo->type,
                'value'    => (float) $promo->value,
                'discount' => $totals['discount'],
            ] : null,
            'promo_error' => $this->resource['promo_error'],

            'summary' => [
                'currency'           => $currency,
                'items_total'        => $totals['items_total'],
                'addons_total'       => $totals['addons_total'],
                'subtotal'           => $totals['subtotal'],
                'delivery_fee'       => $totals['delivery_fee'], // 0: delivered digitally
                'discount'           => $totals['discount'],
                'tax_rate'           => $totals['tax_rate'],
                'tax'                => $totals['tax'],
                'total'              => $totals['total'],
                'prices_include_tax' => $totals['prices_include_tax'],
                'labels'             => [
                    'subtotal' => Money::label($totals['subtotal'], $currency),
                    'discount' => Money::label($totals['discount'], $currency),
                    'tax'      => Money::label($totals['tax'], $currency),
                    'total'    => Money::label($totals['total'], $currency),
                ],
            ],

            'payment_methods' => config('checkout.orders.payment_methods', []),
            'can_checkout'    => $this->resource['available'],
        ];
    }
}
