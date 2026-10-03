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

            'assignment'   => [
                'mode'               => $this->assignment_mode,
                'mode_label'         => $this->assignment_mode ? __('custom_orders.assignment_modes.'.$this->assignment_mode) : null,
                'is_open_for_bidding' => $this->isOpenForBidding(),
                'bidding_opened_at'  => $this->bidding_opened_at?->toIso8601String(),
                'assigned_at'        => $this->assigned_at?->toIso8601String(),
            ],

            'shopper'      => $this->shopperCard(),

            'address'      => $this->hasDeliveryAddress() ? [
                'id'              => $this->delivery_address_id,
                'name'            => $this->delivery_name,
                'phone'           => $this->delivery_phone,
                'location_name'   => $this->delivery_location_name,
                'city'            => $this->delivery_city,
                'district'        => $this->delivery_district,
                'street'          => $this->delivery_street,
                'building_number' => $this->delivery_building_number,
                'full_address'    => $this->delivery_address,
                'latitude'        => $this->delivery_latitude !== null ? (float) $this->delivery_latitude : null,
                'longitude'       => $this->delivery_longitude !== null ? (float) $this->delivery_longitude : null,
            ] : null,

            'delivery_at'  => $this->delivery_at?->toIso8601String(),
            'delivery'     => [
                'at'           => $this->delivery_at?->toIso8601String(),
                'date'         => $this->delivery_date?->toDateString(),
                'slot'         => $this->hasDeliverySlot() ? [
                    'id'    => $this->delivery_slot_id,
                    'label' => $this->delivery_slot_label,
                ] : null,
                'window_start' => $this->delivery_window_start?->toIso8601String(),
                'window_end'   => $this->delivery_window_end?->toIso8601String(),
            ],
            'notes'        => $this->notes,
            'confirmation_notes' => $this->confirmation_notes,

            'budget'       => $this->budget(),
            'final_amount' => $this->final_amount !== null ? Money::format($this->final_amount, $this->currency) : null,

            // The shopper's invoice and what the customer pays (items + fees + delivery + VAT)
            // Where the driver picks the items up, set by the shopper with the invoice
            // (null before); the shopper's own phone number is not shown to the customer
            'pickup'       => $this->whenLoaded('pickupAddress', fn () => $this->pickupDetails() !== null
                ? Arr::except($this->pickupDetails(), ['phone'])
                : null),
            'invoice'      => $this->invoiceDetails(),
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

            'timeline'     => [
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'assigned_at'  => $this->assigned_at?->toIso8601String(),
                'accepted_at'  => $this->accepted_at?->toIso8601String(),
                'started_at'   => $this->started_at?->toIso8601String(),
                'purchased_at' => $this->purchased_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'paid_at'      => $this->paid_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'cancellation' => $this->when($this->status === CustomOrder::STATUS_CANCELLED, fn () => [
                'by'     => $this->cancelled_by,
                'reason' => $this->cancellation_reason,
            ]),

            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
