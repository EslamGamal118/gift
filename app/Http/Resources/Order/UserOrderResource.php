<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\CustomOrder\Concerns\DescribesCustomOrder;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Services\UserOrderService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card of the customer's "My orders" list, in the same shape for store
 * orders (`order_type` = standard) and custom orders (`order_type` = custom).
 *
 * `city` / `district` come from the order's delivery address snapshot (null
 * when there is none, e.g. online gifts have no district). Custom orders also
 * carry `shopper` (name, photo, bio, rating; null until assigned) and `budget`
 * (the items' total budget, as on the details screen). `pricing.total` is the order total for store orders and the
 * final amount for custom orders (null until the shopper sets it, in which
 * case `pricing.budget` is the customer's estimate).
 *
 * Expects the models from UserOrderService (relations loaded, `order_type` set).
 *
 * @mixin Order|CustomOrder
 */
class UserOrderResource extends JsonResource
{
    use DescribesCustomOrder, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource->getAttribute('order_type')
            ?? ($this->resource instanceof CustomOrder ? UserOrderService::TYPE_CUSTOM : UserOrderService::TYPE_STANDARD);

        $card = $type === UserOrderService::TYPE_CUSTOM ? $this->customCard() : $this->standardCard();

        return [
            'order_type'   => $type,
            'id'           => $this->id,
            'order_number' => $this->order_number,
            'status'       => $this->status,
            'status_label' => __(($type === UserOrderService::TYPE_CUSTOM ? 'custom_orders' : 'orders').'.statuses.'.$this->status),
            'tab'          => UserOrderService::tabFor($type, $this->status),
        ] + $card + [
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function standardCard(): array
    {
        return [
            'city'        => $this->addressPart($this->shipping_city),
            'district'    => $this->addressPart($this->shipping_district),
            'items_count' => $this->items->count(),
            'items'       => $this->items->map(fn ($item) => [
                'id'       => $item->id,
                'name'     => $item->product_name,
                'image'    => $this->fileUrl($item->product_image),
                'quantity' => (int) $item->quantity,
                'price'    => Money::format($item->subtotal, $this->currency),
            ])->values(),
            'pricing' => [
                'currency' => $this->currency,
                'total'    => Money::format($this->total_amount, $this->currency),
                'budget'   => null,
            ],
            'payment_status' => $this->payment_status,
            'delivery'       => [
                'type'       => $this->delivery_type,
                'date'       => $this->delivery_date?->toDateString(),
                'slot_label' => $this->delivery_slot_label,
                'at'         => $this->delivery_window_start?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customCard(): array
    {
        return [
            'city'        => $this->addressPart($this->delivery_city),
            'district'    => $this->addressPart($this->delivery_district),
            'shopper'     => $this->shopperCard(),
            'budget'      => $this->budget(),
            'items_count' => $this->items->count(),
            'items'       => $this->items->map(fn ($item) => [
                'id'       => $item->id,
                'name'     => $item->product_name,
                'image'    => $item->media->first()?->url(),
                'quantity' => (int) $item->quantity,
                'price'    => null,
            ])->values(),
            'pricing' => [
                'currency' => $this->currency,
                'total'    => $this->final_amount !== null ? Money::format($this->final_amount, $this->currency) : null,
                'budget'   => $this->budget_min !== null || $this->budget_max !== null ? [
                    'min' => $this->budget_min !== null ? Money::format($this->budget_min, $this->currency) : null,
                    'max' => $this->budget_max !== null ? Money::format($this->budget_max, $this->currency) : null,
                ] : null,
            ],
            'payment_status' => null,
            'delivery'       => [
                'type'       => null,
                'date'       => $this->delivery_date?->toDateString(),
                'slot_label' => $this->delivery_slot_label,
                'at'         => ($this->delivery_at ?? $this->delivery_window_start)?->toIso8601String(),
            ],
        ];
    }

    /**
     * Address snapshot value, null when empty or the "-" placeholder.
     */
    protected function addressPart(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
