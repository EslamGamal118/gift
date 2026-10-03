<?php

namespace App\Http\Resources\Store;

use App\Http\Resources\Concerns\FormatsOrderTimes;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card of the merchant's order list (GET /api/v1/store/orders): reference,
 * status badge, customer, address, how long ago it came in, the items summary
 * with up to THUMBNAILS product images ("+N" for the rest) and a single
 * "Order details" button. Only paid orders are listed (Order::visibleToStore()).
 *
 * Expects `user` and `items` loaded.
 *
 * @mixin Order
 */
class OrderCardResource extends JsonResource
{
    use FormatsOrderTimes, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $images = $this->items->pluck('product_image')->filter()->values();

        return [
            'id' => $this->id,
            'order_reference' => '#'.$this->order_number,
            'status_key' => $this->status,
            'status_label' => __('orders.store_badges.'.$this->storeBadge()),
            'customer_name' => $this->shipping_name ?: $this->user?->name,
            'customer_avatar' => $this->fileUrl($this->user?->avatar),
            'delivery_address' => $this->shipping_address,
            // Since the order reached the store, i.e. was paid
            'time_ago' => $this->timeAgo($this->paid_at ?? $this->created_at),
            'items_count_label' => trans_choice('orders.items_count_label', $quantity = (int) $this->items->sum('quantity'), ['count' => $quantity]),
            'total_amount' => Money::format($this->total_amount, $this->currency),
            'items_preview' => $images->take(OrderResource::THUMBNAILS)->map(fn ($path) => $this->fileUrl($path))->all(),
            'remaining_items_count' => max(0, $images->count() - OrderResource::THUMBNAILS),
            'action_type' => 'view_details',
            'action_label' => __('orders.customer_actions.view_details'),
        ];
    }
}
