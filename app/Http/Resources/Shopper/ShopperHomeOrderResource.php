<?php

namespace App\Http\Resources\Shopper;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\CustomOrder\Concerns\DescribesCustomOrder;
use App\Services\ShopperOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A new order card on the shopper's home screen. Expects `user` loaded and
 * `items_count` from withCount() (ShopperHomeService::newOrders).
 *
 * @mixin \App\Models\CustomOrder
 */
class ShopperHomeOrderResource extends JsonResource
{
    use DescribesCustomOrder, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $key      = ShopperOrderService::statusKey($this->status);
        $customer = $this->whenLoaded('user', fn () => $this->user, null);
        $placed   = $this->submitted_at ?? $this->created_at;
        $count    = (int) ($this->items_count ?? 0);

        return [
            'id'           => $this->id,
            'order_number' => $this->order_number,
            'status'       => $key,                                   // "new"
            'status_label' => $key ? __('custom_orders.shopper_badges.'.$key) : null,
            'customer'     => [
                'name'       => $customer?->name,
                'avatar_url' => $this->fileUrl($customer?->avatar),
            ],
            'created_at'   => $placed?->toIso8601String(),
            'time_ago'     => $placed?->locale(app()->getLocale())->diffForHumans(),
            'cart_size'    => $count,
            'cart_size_label' => trans_choice('custom_orders.home.cart_size', $count, ['count' => $count]),
            'expected_budget' => [
                'min'      => $this->budget_min !== null ? round((float) $this->budget_min, 2) : null,
                'max'      => $this->budget_max !== null ? round((float) $this->budget_max, 2) : null,
                'currency' => $this->currency,
                'label'    => $this->budgetLabel(),
            ],
            'delivery_address' => $this->delivery_address,
        ];
    }
}
