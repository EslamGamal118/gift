<?php

namespace App\Http\Resources\Checkout;

use App\Http\Resources\Concerns\FormatsOrderTimes;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store order. Also the card of the customer's order list: `status_label`,
 * `display_number`, `placed_at_label`, `store.address`, the items preview and
 * `actions` (which button the card shows).
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    use FormatsOrderTimes, ResolvesFileUrls;

    /**
     * Product images shown on a card; the rest is the "+N" badge.
     */
    public const PREVIEW_ITEMS = 3;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'display_number' => '#'.$this->order_number,
            // The payment this order shares with the other stores of the same checkout
            'checkout_id' => $this->checkout_group_id,
            'status' => $this->status,
            'status_label' => __('orders.statuses.'.$this->status),
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'is_payable' => $this->isPayable(),
            'store' => $this->storeProfile ? [
                'id' => $this->store_id,
                'store_name' => $this->storeProfile->store_name,
                'logo' => $this->fileUrl($this->storeProfile->logo),
                // The store's location is its main branch, e.g. "الرياض، حي الياسمين"
                'address' => $this->storeProfile->mainBranch?->address,
            ] : null,
            'address' => [
                'id' => $this->address_id,
                'location_name' => $this->shipping_location_name,
                'city' => $this->shipping_city,
                'district' => $this->shipping_district,
                'street' => $this->shipping_street,
                'building_number' => $this->shipping_building_number,
                'full_address' => $this->shipping_address,
                'phone' => $this->shipping_phone,
                'name' => $this->shipping_name,
            ],
            'delivery' => [
                'type' => $this->delivery_type,
                'is_instant' => $this->isInstantDelivery(),
                'date' => $this->delivery_date?->toDateString(),
                'slot_label' => $this->delivery_slot_label,
                'window_start' => $this->delivery_window_start?->toIso8601String(),
                'window_end' => $this->delivery_window_end?->toIso8601String(),
                'eta_minutes' => $this->estimated_minutes_min !== null ? [
                    'min' => $this->estimated_minutes_min,
                    'max' => $this->estimated_minutes_max,
                ] : null,
            ],
            'gift_message' => $this->gift_message,
            'promo_code' => $this->promo_code,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'items_preview' => $this->whenLoaded('items', fn () => $this->items->take(self::PREVIEW_ITEMS)->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product_name,
                'image' => $this->fileUrl($item->product_image),
                'quantity' => (int) $item->quantity,
            ])->values()),
            'remaining_items_count' => $this->whenLoaded('items', fn () => max(0, $this->items->count() - self::PREVIEW_ITEMS)),
            'actions' => $this->actions(),
            'totals' => [
                'currency' => $this->currency,
                'subtotal' => (float) $this->subtotal,
                'delivery_fee' => (float) $this->delivery_fee,
                'express_fee' => (float) $this->express_fee,
                'discount' => (float) $this->discount_amount,
                'tax_rate' => (float) $this->tax_rate,
                'tax' => (float) $this->tax_amount,
                'total' => (float) $this->total_amount,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'placed_at_label' => $this->placedAtLabel(),
        ];
    }

    /**
     * An order being prepared or delivered is tracked; any other one (finished,
     * cancelled, unpaid) opens its details.
     *
     * @return array<string, mixed>
     */
    protected function actions(): array
    {
        $trackable = in_array($this->status, Order::ACTIVE_STATUSES, true);
        $primary = $trackable ? 'track' : 'details';

        return [
            'primary' => $primary,
            'primary_label' => __('orders.customer_actions.'.$primary),
            'can_track' => $trackable,
            'can_cancel' => $this->isCancellable(),
        ];
    }
}
