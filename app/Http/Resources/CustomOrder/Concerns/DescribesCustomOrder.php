<?php

namespace App\Http\Resources\CustomOrder\Concerns;

use App\Models\ShopperProfile;
use App\Models\User;
use App\Support\Money;

/**
 * Pieces of a custom order rendered the same way on its details screen
 * (CustomOrderResource) and on the customer's order list (UserOrderResource).
 * Expects a JsonResource wrapping a CustomOrder that also uses ResolvesFileUrls.
 *
 * @mixin \App\Models\CustomOrder
 */
trait DescribesCustomOrder
{
    /**
     * The assigned shopper: name, photo, bio and rating only (null when no
     * shopper is assigned yet). Relations `shopper` and `shopperProfile` are
     * used when loaded.
     *
     * @return array{name: ?string, photo: ?string, bio: ?string, rating: array{average: float, count: int}}|null
     */
    protected function shopperCard(): ?array
    {
        if (! $this->shopper_id) {
            return null;
        }

        /** @var ShopperProfile|null $profile */
        $profile = $this->resource->relationLoaded('shopperProfile') ? $this->shopperProfile : null;
        /** @var User|null $shopper */
        $shopper = $this->resource->relationLoaded('shopper') ? $this->shopper : null;

        return [
            'name'   => $shopper?->name,
            'photo'  => $this->fileUrl($profile?->personal_photo ?: $shopper?->avatar),
            'bio'    => $profile?->bio,
            'phone'  => $shopper?->phone,
            'rating' => [
                'average' => round((float) ($profile?->rating_avg ?? 0), 1),
                'count'   => (int) ($profile?->rating_count ?? 0),
            ],
        ];
    }

    /**
     * Budget for all items (sum of quantity x expected price, or the range the
     * customer entered): each bound as Money::format(), null when unset.
     *
     * @return array{currency: string, min: ?array, max: ?array, label: ?string}
     */
    protected function budget(): array
    {
        return [
            'currency' => $this->currency,
            'min'      => $this->budget_min !== null ? Money::format($this->budget_min, $this->currency) : null,
            'max'      => $this->budget_max !== null ? Money::format($this->budget_max, $this->currency) : null,
            'label'    => $this->budgetLabel(),
        ];
    }

    /**
     * "150.00 - 300.00 SAR", "from 150.00 SAR", "up to 300.00 SAR" or null.
     */
    protected function budgetLabel(): ?string
    {
        $min = $this->budget_min !== null ? (float) $this->budget_min : null;
        $max = $this->budget_max !== null ? (float) $this->budget_max : null;

        return match (true) {
            $min !== null && $max !== null => number_format($min, 2).' - '.Money::label($max, $this->currency),
            $min !== null                  => Money::label($min, $this->currency),
            $max !== null                  => Money::label($max, $this->currency),
            default                        => null,
        };
    }

    /**
     * Where the driver picks the items up (the shopper's address), or null
     * before the invoice. Relation `pickupAddress` must be loaded.
     *
     * @return array<string, mixed>|null
     */
    protected function pickupDetails(): ?array
    {
        $address = $this->pickupAddress;

        return $address ? [
            'id'              => $address->id,
            'city'            => $address->city,
            'district'        => $address->district,
            'full_address'    => $address->toLine(),
        ] : null;
    }

    /**
     * Delivery by the delivery company (Alshrouq) once the order was sent to
     * it, with the latest driver details; null before.
     *
     * @return array<string, mixed>|null
     */
    protected function deliveryTracking(): ?array
    {
        if (! $this->delivery_reference) {
            return null;
        }

        $driver = (array) $this->delivery_driver;

        return [
            'provider'       => 'alshrouq',
            'reference'      => $this->delivery_reference,
            'provider_status' => $this->delivery_status,
            'updated_at'     => $this->delivery_updated_at?->toIso8601String(),
            'driver'         => $driver ? [
                'name'         => $driver['name'] ?? null,
                'phone'        => $driver['phone'] ?? null,
                'tracking_url' => $driver['tracking_url'] ?? null,
                'location'     => $driver['location'] ?? null,
            ] : null,
        ];
    }

    /**
     * The purchase invoice the shopper uploaded, or null.
     *
     * @return array{image_url: ?string, submitted_at: ?string}|null
     */
    protected function invoiceDetails(): ?array
    {
        return $this->invoice_path ? [
            'image_url'    => $this->resource->invoiceUrl(),
            'submitted_at' => $this->invoice_submitted_at?->toIso8601String(),
        ] : null;
    }

    /**
     * Actual prices once the invoice is submitted, each as Money::format(); null before:
     * items subtotal + shopper fees = final_amount, + delivery fee + VAT = total.
     *
     * @return array{subtotal: array, delivery_fee: array, shopper_fees: array, total: array}|null
     */
    protected function pricing(): ?array
    {
        return $this->total_amount !== null ? [
            'subtotal'     => Money::format((float) $this->final_amount - (float) $this->shopper_fees, $this->currency),
            'shopper_fees' => Money::format($this->shopper_fees, $this->currency),
            'final_amount' => Money::format($this->final_amount, $this->currency),
            'delivery_fee' => Money::format($this->delivery_fee, $this->currency),
            'tax'          => Money::format($this->tax_amount, $this->currency),
            'total'        => Money::format($this->total_amount, $this->currency),
        ] : null;
    }
}
