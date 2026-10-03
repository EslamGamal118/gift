<?php

namespace App\Http\Resources\Checkout;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * One card of GET /api/v1/orders: store, reference, date, status badge,
 * product images ("+N" for the rest) and a single "Order details" button.
 * The full order is GET /api/v1/orders/{id} (OrderDetailsResource).
 *
 * Expects RELATIONS loaded.
 *
 * @mixin Order
 */
class OrderCardResource extends OrderResource
{
    public const RELATIONS = ['storeProfile.mainBranch', 'items'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = $this->storeProfile;

        return [
            'id' => $this->id,
            'store_name' => $store?->store_name,
            'store_logo' => $this->fileUrl($store?->logo),
            'store_branch' => $store?->mainBranch?->address,
            'order_reference' => '#'.$this->order_number,
            'created_at_formatted' => $this->placedAtLabel(),
            'status_key' => $this->status,
            'status_label' => $this->badgeLabel(),
            'items_preview' => $this->items->take(self::PREVIEW_ITEMS)->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product_name,
                'image' => $this->fileUrl($item->product_image),
            ])->values(),
            'remaining_items_count' => max(0, $this->items->count() - self::PREVIEW_ITEMS),
            'action_type' => 'view_details',
            'action_label' => __('orders.customer_actions.view_details'),
        ];
    }

    /**
     * The badge wording ("مكتمل"), else the customer status label ("قيد المراجعة").
     */
    protected function badgeLabel(): string
    {
        $key = 'orders.badges.'.$this->status;

        return trans()->has($key) ? __($key) : __("orders.customer_statuses.{$this->status}.label");
    }
}
