<?php

namespace App\Http\Requests\Checkout;

use App\Models\Cart;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Price a delivery option without saving it (e.g. when the instant toggle flips).
 */
class DeliveryQuoteRequest extends FormRequest
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
            'delivery_type' => ['nullable', Rule::in(Cart::DELIVERY_TYPES)],
            'address_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'delivery_type' => __('validation.attributes.delivery_type'),
            'address_id' => __('validation.attributes.address_id'),
        ];
    }
}
