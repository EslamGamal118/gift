<?php

namespace App\Http\Resources\Checkout;

use App\Models\CheckoutGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A placed checkout: the single payment and the per-store orders it was split into.
 *
 * @mixin CheckoutGroup
 */
class CheckoutGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'is_payable' => $this->isPayable(),
            'is_paid' => $this->isPaid(),
            'totals' => [
                'currency' => $this->currency,
                'subtotal' => (float) $this->subtotal,
                'delivery_fee' => (float) $this->delivery_fee,
                'express_fee' => (float) $this->express_fee,
                'discount' => (float) $this->discount_amount,
                'tax' => (float) $this->tax_amount,
                'total' => (float) $this->total_amount,
            ],
            'orders_count' => $this->whenLoaded('orders', fn () => $this->orders->count()),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
