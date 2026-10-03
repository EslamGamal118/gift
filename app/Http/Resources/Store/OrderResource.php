<?php

namespace App\Http\Resources\Store;

use App\Http\Resources\Checkout\OrderItemResource;
use App\Http\Resources\Concerns\FormatsOrderTimes;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\Order;
use App\Models\User;
use App\Services\StoreOrderService;
use App\Support\Money;
use App\Support\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Store-facing order: list card fields always, full details when the
 * relations are loaded (items, timeline, captain).
 *
 * `badge` is the card identifier (new, accepted, preparing, ready_for_pickup,
 * out_for_delivery, completed, cancelled); `cart` summarises the items with up
 * to THUMBNAILS product images and the "+X" remainder.
 *
 * The details screen reads `header_label`, `status_badge`, `customer`,
 * `products`, `delivery_info` and `payment_summary` (amounts are
 * Money::format() objects). The list uses OrderCardResource.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    use FormatsOrderTimes, ResolvesFileUrls;

    public const THUMBNAILS = 3;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->whenLoaded('user');
        $phone = $this->shipping_phone ?: $this->user?->phone;

        return [
            'id' => $this->id,
            'reference' => $this->order_number,
            'order_reference' => '#'.$this->order_number,
            // "طلب #GFT-10254 - اليوم، 10:30 ص"
            'header_label' => __('orders.store_order_header', ['reference' => '#'.$this->order_number, 'date' => $this->placedAtLabel()]),
            'placed_at_label' => $this->placedAtLabel(),
            'status' => $this->status,
            'status_label' => OrderStateMachine::statusLabel($this->status),
            'badge' => $this->storeBadge(),
            'status_badge' => [
                'key' => $this->storeBadge(),
                'label' => __('orders.store_badges.'.$this->storeBadge()),
            ],
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'is_paid' => $this->isPaid(),
            'placed_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'elapsed' => $this->elapsed(),

            'available_actions' => app(OrderStateMachine::class)->availableActions($this->resource),
            'next_statuses' => app(StoreOrderService::class)->nextStatuses($this->resource),

            'customer' => [
                'id' => $this->user_id,
                'name' => $this->shipping_name ?: $this->user?->name,
                'phone' => $phone,
                'call_url' => $phone ? 'tel:+'.ltrim(preg_replace('/\D+/', '', $phone) ?? '', '+') : null,
                'can_call' => (bool) $phone,
                'avatar' => $customer instanceof User ? $this->fileUrl($customer->avatar) : null,
            ],

            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'cart' => $this->whenLoaded('items', fn () => $this->cart()),
            'products' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'image' => $this->fileUrl($item->product_image),
                'quantity' => (int) $item->quantity,
                'unit_price' => Money::format($item->unit_price, $this->currency),
                'addons' => $item->relationLoaded('addons') ? $item->addons->map(fn ($addon) => [
                    'name' => $addon->name,
                    'quantity' => (int) $addon->quantity,
                    'total' => Money::format($addon->subtotal, $this->currency),
                ])->values()->all() : [],
                'total' => Money::format($item->subtotal, $this->currency),   // incl. add-ons
            ])->values()->all()),

            'delivery_info' => [
                'address' => $this->shipping_address,
                'is_instant' => $this->isInstantDelivery(),
                'expected_time_label' => $this->expectedTimeLabel(),
                'fee' => Money::format((float) $this->delivery_fee + (float) $this->express_fee, $this->currency),
            ],

            'payment_summary' => [
                'subtotal' => Money::format($this->subtotal, $this->currency),
                'delivery_fee' => Money::format($this->delivery_fee, $this->currency),
                'express_fee' => Money::format($this->express_fee, $this->currency),
                'tax_rate' => (float) $this->tax_rate,
                'tax' => Money::format($this->tax_amount, $this->currency),
                'discount' => Money::format($this->discount_amount, $this->currency),
                'total' => Money::format($this->total_amount, $this->currency),
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
                'address' => [
                    'location_name' => $this->shipping_location_name,
                    'city' => $this->shipping_city,
                    'district' => $this->shipping_district,
                    'street' => $this->shipping_street,
                    'building_number' => $this->shipping_building_number,
                    'full_address' => $this->shipping_address,
                    'latitude' => $this->shipping_latitude !== null ? (float) $this->shipping_latitude : null,
                    'longitude' => $this->shipping_longitude !== null ? (float) $this->shipping_longitude : null,
                ],
            ],

            'captain' => $this->whenLoaded('captain', fn () => $this->captain ? [
                'id' => $this->captain->id,
                'name' => $this->captain->name,
                'phone' => $this->captain->phone,
            ] : null),

            'gift_message' => $this->gift_message,
            // Online gift: who receives it (the store prepares it for them)
            'is_online_gift' => $this->whenLoaded('gift', fn () => $this->gift !== null),
            'gift' => $this->whenLoaded('gift', fn () => $this->gift ? [
                'id' => $this->gift->id,
                'recipient_name' => $this->gift->recipient_name,
                'recipient_phone' => $this->gift->recipient_phone,
                'recipient_email' => $this->gift->recipient_email,
                'message' => $this->gift->gift_message,
            ] : null),
            'promo_code' => $this->promo_code,

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

            'timestamps' => [
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'preparing_at' => $this->preparing_at?->toIso8601String(),
                'ready_at' => $this->ready_at?->toIso8601String(),
                'dispatched_at' => $this->dispatched_at?->toIso8601String(),
                'delivered_at' => $this->delivered_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],

            'cancellation' => $this->when($this->isCancelled(), fn () => [
                'by' => $this->cancelled_by,
                'reason' => $this->cancellation_reason,
            ]),

            'timeline' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(fn ($h) => [
                'from' => $h->from_status,
                'to' => $h->to_status,
                'to_label' => OrderStateMachine::statusLabel($h->to_status),
                'actor_type' => $h->actor_type,
                'actor_name' => $h->actor?->name,
                'reason' => $h->reason,
                'at' => $h->created_at?->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * Time since the order reached the store (payment time, falling back to placement).
     *
     * @return array{minutes: int, human: string}|null
     */
    protected function elapsed(): ?array
    {
        $since = $this->paid_at ?? $this->created_at;

        return $since ? [
            'minutes' => (int) $since->diffInMinutes(now()),
            'human' => $since->copy()->locale(app()->getLocale())->diffForHumans(),
        ] : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function cart(): array
    {
        $images = $this->items->pluck('product_image')->filter()->values();

        return [
            'items_count' => (int) $this->items->sum('quantity'),
            'lines_count' => $this->items->count(),
            'total' => Money::format($this->total_amount, $this->currency),
            'thumbnails' => $images->take(self::THUMBNAILS)->map(fn ($path) => $this->fileUrl($path))->all(),
            'extra_count' => max(0, $images->count() - self::THUMBNAILS),
        ];
    }
}
