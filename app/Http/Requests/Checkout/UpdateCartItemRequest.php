<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required_without:addon_ids', 'nullable', 'integer', 'min:1', 'max:'.config('checkout.cart.max_quantity_per_item', 50)],
            'addon_ids' => ['sometimes', 'array', 'max:20'],
            'addon_ids.*' => ['integer', 'distinct', 'exists:addons,id'],
        ];
    }

    public function quantity(): ?int
    {
        $q = $this->validated('quantity');

        return $q === null ? null : (int) $q;
    }

    /**
     * Null when the client did not send `addon_ids` (keep current add-ons).
     *
     * @return array<int, int>|null
     */
    public function addonIds(): ?array
    {
        if (! $this->has('addon_ids')) {
            return null;
        }

        return array_map('intval', $this->validated('addon_ids') ?? []);
    }

    public function attributes(): array
    {
        return [
            'quantity' => __('validation.attributes.quantity'),
            'addon_ids' => __('validation.attributes.addon_ids'),
            'addon_ids.*' => __('validation.attributes.addon_ids'),
        ];
    }
}
