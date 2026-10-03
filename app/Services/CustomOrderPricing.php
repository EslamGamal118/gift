<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use Illuminate\Support\Collection;

/**
 * Server-side price of a custom order, never taken from the client:
 *
 *   subtotal      = sum(item unit_price x quantity) + shopper_fees   (= final_amount)
 *   tax_amount    = VAT on subtotal + delivery_fee (config `checkout.tax`, as store checkout)
 *   total_amount  = subtotal + delivery_fee + tax_amount
 *
 * With `checkout.tax.prices_include_tax` the VAT is extracted instead and the
 * total is subtotal + delivery_fee (CheckoutService::totals()).
 */
class CustomOrderPricing
{
    public function __construct(protected CheckoutService $checkout) {}

    /**
     * Sum of unit price x quantity over the order's items, or null while any
     * item has no price.
     *
     * @param  Collection<int, CustomOrderItem>  $items
     */
    public function itemsSubtotal(Collection $items): ?float
    {
        if ($items->contains(fn (CustomOrderItem $item) => $item->unit_price === null)) {
            return null;
        }

        return round($items->sum(fn (CustomOrderItem $item) => $item->totalPrice()), 2);
    }

    /**
     * Fill (not save) final_amount, shopper_fees, delivery_fee, tax_amount and total_amount.
     *
     * @return array{subtotal: float, delivery_fee: float, tax_rate: float, tax: float, total: float, prices_include_tax: bool}
     */
    public function apply(CustomOrder $order, float $itemsSubtotal, float $shopperFees, float $deliveryFee): array
    {
        $subtotal = round($itemsSubtotal + $shopperFees, 2);
        $totals   = $this->checkout->totals($subtotal, 0, $deliveryFee, 0);

        $order->forceFill([
            'final_amount' => $subtotal,
            'shopper_fees' => round($shopperFees, 2),
            'delivery_fee' => $totals['delivery_fee'],
            'tax_amount'   => $totals['tax'],
            'total_amount' => $totals['total'],
        ]);

        return [
            'subtotal'           => $subtotal,
            'delivery_fee'       => $totals['delivery_fee'],
            'tax_rate'           => $totals['tax_rate'],
            'tax'                => $totals['tax'],
            'total'              => $totals['total'],
            'prices_include_tax' => $totals['prices_include_tax'],
        ];
    }
}
