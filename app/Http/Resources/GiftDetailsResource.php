<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Online gift details / payment screen, built from GiftService::details():
 * the store, the gift, the current selection (incl. any add-ons sent in
 * `addon_ids`, priced into the summary) and the financial summary that POST /gifts/checkout will charge.
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
                'description' => $store->description,
                'logo'        => $this->fileUrl($store->logo),
                'rating'      => [
                    'average' => round((float) $store->rating_avg, 1),
                    'count'   => (int) $store->rating_count,
                ],
            ] : null,

            'gift' => [
                'id'             => $product->id,
                'name'           => $product->name,
                'description'    => $product->description,
                'image'          => $this->fileUrl($product->image),
                'price'          => Money::format($product->price, $currency),
                'is_digital'     => true,
                'in_stock'       => $product->stock_quantity > 0,
                // Cap for the quantity stepper; checkout re-validates the stock.
                'max_quantity'   => min((int) $product->stock_quantity, (int) config('gifts.max_quantity', 10)),
            ],

            'selection' => [
                'quantity'   => $this->resource['quantity'],
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
                'subtotal'           => $totals['subtotal'],
                'delivery_fee'       => $totals['delivery_fee'], // 0: delivered digitally
                'discount'           => $totals['discount'],
                'tax_rate'           => $totals['tax_rate'],
                'tax'                => $totals['tax'],
                'total'              => $totals['total'],
                'prices_include_tax' => $totals['prices_include_tax'],
            ],

            'payment_methods' => config('checkout.orders.payment_methods', []),
            'can_checkout'    => $this->resource['available'],
        ];
    }
}
