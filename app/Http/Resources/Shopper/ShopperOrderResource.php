<?php

namespace App\Http\Resources\Shopper;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\CustomOrder\Concerns\DescribesCustomOrder;
use App\Models\CustomOrder;
use App\Services\ShopperOrderService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card of the shopper's order list: status badge, customer, items count,
 * the items' expected budget and where / when to deliver.
 *
 * Expects `user` (customer) loaded and `items_count` from withCount().
 * When `items` is loaded (a completed order returned by the status update)
 * the purchased items are listed with the price paid per unit and per line.
 * `invoice` / `pricing` are null until the shopper submits the invoice.
 *
 * @mixin CustomOrder
 */
class ShopperOrderResource extends JsonResource
{
    use DescribesCustomOrder, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $key      = ShopperOrderService::statusKey($this->status);
        $customer = $this->whenLoaded('user', fn () => $this->user, null);

        return [
            'id'           => $this->id,
            'order_number' => $this->order_number,
            'status'       => $this->status,
            'status_badge' => [
                'key'   => $key,
                'label' => $key ? __('custom_orders.shopper_badges.'.$key) : null,
            ],
            'tab'          => ShopperOrderService::tabFor($this->status),
            'customer'     => [
                'name'  => $customer?->name,
                'photo' => $this->fileUrl($customer?->avatar),
            ],
            'items_count'  => (int) ($this->items_count ?? 0),
            'budget'       => $this->budget(),
            'final_amount' => $this->final_amount !== null ? Money::format($this->final_amount, $this->currency) : null,
            'items'        => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'item_id'      => $item->id,
                'product_name' => $item->product_name,
                'quantity'     => (int) $item->quantity,
                'unit_price'   => $item->unit_price !== null ? round((float) $item->unit_price, 2) : null,
                'total_price'  => $item->totalPrice(),
                'currency'     => $this->currency,
            ])->values()),
            'pickup'       => $this->whenLoaded('pickupAddress', fn () => $this->pickupDetails()),
            'invoice'      => $this->invoiceDetails(),
            'pricing'      => $this->pricing(),
            'delivery'     => [
                'city'       => $this->delivery_city,
                'district'   => $this->delivery_district,
                'address'    => $this->delivery_address,
                'date'       => $this->delivery_date?->toDateString(),
                'slot_label' => $this->delivery_slot_label,
                'at'         => ($this->delivery_at ?? $this->delivery_window_start)?->toIso8601String(),
            ],
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
