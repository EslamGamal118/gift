<?php

namespace App\Http\Resources\CustomOrder;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\CustomOrder\Concerns\DescribesCustomOrder;
use App\Models\CustomOrder;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * A custom (personal shopper) order as the customer sees it: status with a
 * localized label, the chosen shopper (or the bidding state), delivery
 * snapshot, budget, items with reference images, and offers when bidding.
 *
 * Works for both the list (items not loaded -> `items_count` only) and the
 * details screen (items, media, shopper profile and bids loaded).
 *
 * @mixin CustomOrder
 */
class CustomOrderResource extends JsonResource
{
    use DescribesCustomOrder, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'order_number' => $this->order_number,
            'status'       => $this->status,
            'status_label' => __('custom_orders.statuses.'.$this->status),
            'is_final'     => $this->isFinal(),
            'can_cancel'   => $this->isCancellable(),
            'can_assign'   => $this->isAssignable(),
            'can_confirm'  => $this->isConfirmable(),

            'shopper'      => $this->shopperCard(),

           'address' => $this->hasDeliveryAddress() ? [
    'id'           => $this->delivery_address_id,
    'full_address' => $this->delivery_address, // النص الكامل اللي ظاهر في التصميم
    'city'         => $this->delivery_city,
    'district'     => $this->delivery_district,
] : null,

            'delivery_at'  => $this->delivery_at?->toIso8601String(),
            'notes'        => $this->notes,
            'confirmation_notes' => $this->confirmation_notes,
            'final_amount' => $this->final_amount !== null ? Money::format($this->final_amount, $this->currency) : null,

            // The shopper's invoice and what the customer pays (items + fees + delivery + VAT)
            // Where the driver picks the items up, set by the shopper with the invoice
            // (null before); the shopper's own phone number is not shown to the customer
            'pickup'       => $this->whenLoaded('pickupAddress', fn () => $this->pickupDetails() !== null
                ? Arr::except($this->pickupDetails(), ['phone'])
                : null),
            'invoice'      => $this->invoiceDetails(),
            'delivery_tracking' => $this->deliveryTracking(),
            'pricing'      => $this->pricing(),
            'payment'      => [
                'status'     => $this->payment_status,
                'method'     => $this->payment_method,
                'paid_at'    => $this->paid_at?->toIso8601String(),
                'is_paid'    => $this->resource->isPaid(),
                'is_payable' => $this->resource->isPayable(),   // POST /checkout/pay
            ],

            'items_count'  => (int) ($this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : 0)),
            'items'        => $this->whenLoaded('items', fn () => CustomOrderItemResource::collection(
                $this->items->each(fn ($item) => $item->setRelation('customOrder', $this->resource))
            )),

            'bids'         => $this->whenLoaded('bids', fn () => CustomOrderBidResource::collection($this->bids)),
            'cancellation' => $this->when($this->status === CustomOrder::STATUS_CANCELLED, fn () => [
                'by'     => $this->cancelled_by,
                'reason' => $this->cancellation_reason,
            ]),

            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
