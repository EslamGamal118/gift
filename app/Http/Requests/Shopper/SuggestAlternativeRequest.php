<?php

namespace App\Http\Requests\Shopper;

use Illuminate\Validation\Rule;

/**
 * POST /shopper/orders/{customOrder}/alternatives  (multipart)
 * { item_id, product_name, price, reason, image? }
 *
 * `item_id` must be an item of this order.
 */
class SuggestAlternativeRequest extends ShopperOrderActionRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'product_name' => $this->filled('product_name') ? trim((string) $this->input('product_name')) : null,
            'reason'       => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('custom_orders.items');

        return [
            'item_id'      => ['required', 'integer', Rule::exists('custom_order_items', 'id')->where('custom_order_id', $this->order()->id)],
            'product_name' => ['required', 'string', 'min:2', 'max:150'],
            'price'        => ['required', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'reason'       => ['required', 'string', 'min:2', 'max:255'],
            'image'        => ['nullable', 'file', 'image', 'mimes:'.implode(',', $limits['image_mimes']), 'max:'.$limits['max_image_kb']],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.exists' => __('custom_orders.item_not_in_order'),
        ];
    }

    /**
     * @return array{item_id: int, product_name: string, price: float, reason: string}
     */
    public function alternative(): array
    {
        return [
            'item_id'      => (int) $this->validated('item_id'),
            'product_name' => (string) $this->validated('product_name'),
            'price'        => (float) $this->validated('price'),
            'reason'       => (string) $this->validated('reason'),
        ];
    }

    public function attributes(): array
    {
        return [
            'item_id'      => __('validation.attributes.item_id'),
            'product_name' => __('validation.attributes.product_name'),
            'price'        => __('validation.attributes.price'),
            'reason'       => __('validation.attributes.reason'),
            'image'        => __('validation.attributes.image'),
        ];
    }
}
