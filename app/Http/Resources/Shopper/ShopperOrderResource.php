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
            'delivery_tracking' => $this->deliveryTracking(),
            'status_badge' => [
                'key'   => $key,
                'label' => $key ? __('custom_orders.shopper_badges.'.$key) : null,
            ],
            'customer'     => [
                'name'  => $customer?->name,
                'photo' => $this->fileUrl($customer?->avatar),
            ],
            'items_count'  => (int) ($this->items_count ?? 0),
            'budget'       => $this->budget(),
            'final_amount' => $this->final_amount !== null ? Money::format($this->final_amount, $this->currency) : null,
            'delivery'     => [
                'city'       => $this->delivery_city,
                'district'   => $this->delivery_district,
                'address'    => $this->delivery_address,
            ],
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
