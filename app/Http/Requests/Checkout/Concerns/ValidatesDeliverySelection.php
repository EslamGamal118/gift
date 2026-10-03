<?php

namespace App\Http\Requests\Checkout\Concerns;

use App\Models\Cart;
use Illuminate\Validation\Rule;

/**
 * The delivery choice fields (instant delivery, or a date + time slot), shared
 * by the delivery screen and place-order so both accept the same payload.
 */
trait ValidatesDeliverySelection
{
    /**
     * The toggle on the screen may be sent as `instant: true` instead of a type.
     */
    protected function normaliseDeliveryType(): void
    {
        if (! $this->has('delivery_type') && $this->has('instant')) {
            $this->merge([
                'delivery_type' => $this->boolean('instant') ? Cart::DELIVERY_INSTANT : Cart::DELIVERY_SCHEDULED,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function deliveryRules(bool $required = true): array
    {
        return [
            'delivery_type' => [$required ? 'required' : 'nullable', Rule::in(Cart::DELIVERY_TYPES)],
            'delivery_date' => ['required_if:delivery_type,'.Cart::DELIVERY_SCHEDULED, 'nullable', 'date_format:Y-m-d'],
            'delivery_slot_id' => ['required_if:delivery_type,'.Cart::DELIVERY_SCHEDULED, 'nullable', 'integer', 'exists:delivery_slots,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function deliveryAttributes(): array
    {
        return [
            'delivery_type' => __('validation.attributes.delivery_type'),
            'delivery_date' => __('validation.attributes.delivery_date'),
            'delivery_slot_id' => __('validation.attributes.delivery_slot_id'),
        ];
    }

    public function hasDeliverySelection(): bool
    {
        return $this->validated('delivery_type') !== null;
    }

    public function deliveryType(): string
    {
        return (string) $this->validated('delivery_type');
    }

    public function deliveryDate(): ?string
    {
        return $this->validated('delivery_date');
    }

    public function slotId(): ?int
    {
        $id = $this->validated('delivery_slot_id');

        return $id === null ? null : (int) $id;
    }
}
