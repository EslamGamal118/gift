<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a product (with optional add-ons) to the cart. The single-store rule and
 * stock checks are enforced by CartService.
 */
class AddCartItemRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('checkout.cart.max_quantity_per_item', 50)],
            'addon_ids' => ['nullable', 'array', 'max:20'],
            'addon_ids.*' => ['integer', 'distinct', 'exists:addons,id'],
        ];
    }

    public function quantity(): int
    {
        return (int) ($this->validated('quantity') ?? 1);
    }

    /**
     * @return array<int, int>
     */
    public function addonIds(): array
    {
        return array_map('intval', $this->validated('addon_ids') ?? []);
    }

    public function attributes(): array
    {
        return [
            'product_id' => __('validation.attributes.product_id'),
            'quantity' => __('validation.attributes.quantity'),
            'addon_ids' => __('validation.attributes.addon_ids'),
            'addon_ids.*' => __('validation.attributes.addon_ids'),
        ];
    }
}
