<?php

namespace App\Http\Resources\Shopper;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\CustomOrder\Concerns\DescribesCustomOrder;
use App\Http\Resources\CustomOrder\CustomOrderItemResource;
use App\Models\CustomOrder;
use App\Services\ShopperOrderService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order in the shopper app (GET /shopper/orders/{id}): status and next
 * step, the customer, where / when to deliver, the items with their expected
 * prices, reference images and any suggested alternatives, the purchase
 * invoice with the price breakdown, and the timeline.
 *
 * Expects ShopperOrderService::details() (user, items.media, items.alternatives, items_count).
 *
 * @mixin CustomOrder
 */
class ShopperOrderDetailResource extends JsonResource
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
            'id'              => $this->id,
            'order_number'    => $this->order_number,
            'status'          => $this->status,
            'status_badge'    => [
                'key'   => $key,
                'label' => $key ? __('custom_orders.shopper_badges.'.$key) : null,
            ],
            'tab'             => ShopperOrderService::tabFor($this->status),
            'assignment_mode' => $this->assignment_mode,

            // What the shopper can do next (POST /shopper/orders/{id}/status, /alternatives)
            'actions'         => [
                'next_status'          => ShopperOrderService::nextStatus($this->resource),
                'can_suggest_alternatives' => in_array($this->status, ShopperOrderService::SHOPPING_STATUSES, true),
                // Reject a new order or give up one in progress (status = cancelled + reason)
                'can_cancel'               => $this->resource->isCancellable(),
                // POST /shopper/orders/{id}/submit-invoice-prices
                'can_submit_invoice'       => in_array($this->status, ShopperOrderService::INVOICE_STATUSES, true),
            ],

            'customer'        => [
                'id'    => $this->user_id,
                'name'  => $customer?->name,
                'phone' => $customer?->phone,
                'photo' => $this->fileUrl($customer?->avatar),
            ],

            // Where to deliver (عنوان التسليم), set by the customer
            'delivery'        => [
                'address_id'      => $this->delivery_address_id,
                'recipient_name'  => $this->delivery_name,
                'recipient_phone' => $this->delivery_phone,
                'location_name'   => $this->delivery_location_name,
                'city'            => $this->delivery_city,
                'district'        => $this->delivery_district,
                'street'          => $this->delivery_street,
                'building_number' => $this->delivery_building_number,
                'full_address'    => $this->delivery_address,
                'latitude'        => $this->delivery_latitude !== null ? (float) $this->delivery_latitude : null,
                'longitude'       => $this->delivery_longitude !== null ? (float) $this->delivery_longitude : null,
                'date'            => $this->delivery_date?->toDateString(),
                'slot_label'      => $this->delivery_slot_label,
                'at'              => ($this->delivery_at ?? $this->delivery_window_start)?->toIso8601String(),
                'window_end'      => $this->delivery_window_end?->toIso8601String(),
                // Delivery instructions entered when the customer confirmed
                'notes'           => $this->confirmation_notes,
            ],
            'notes'           => $this->notes,

            'budget'          => $this->budget(),
            'final_amount'    => $this->final_amount !== null ? Money::format($this->final_amount, $this->currency) : null,

            // Where the driver picks the items up (عنوان الاستلام), set with the invoice
            'pickup'          => $this->whenLoaded('pickupAddress', fn () => $this->pickupDetails()),
            'invoice'         => $this->invoiceDetails(),
            'pricing'         => $this->pricing(),

            'items_count'     => (int) ($this->items_count ?? $this->items->count()),
            'items'           => $this->whenLoaded('items', fn () => CustomOrderItemResource::collection(
                $this->items->each(fn ($item) => $item->setRelation('customOrder', $this->resource))
            )),

            'timeline'        => [
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'assigned_at'  => $this->assigned_at?->toIso8601String(),
                'accepted_at'  => $this->accepted_at?->toIso8601String(),
                'started_at'   => $this->started_at?->toIso8601String(),
                'purchased_at' => $this->purchased_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'paid_at'      => $this->paid_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'cancellation'    => $this->when($this->status === CustomOrder::STATUS_CANCELLED, fn () => [
                'by'     => $this->cancelled_by,
                'reason' => $this->cancellation_reason,
            ]),

            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
