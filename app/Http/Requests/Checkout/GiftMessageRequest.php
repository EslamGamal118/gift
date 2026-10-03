<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class GiftMessageRequest extends FormRequest
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
            'gift_message' => ['present', 'nullable', 'string', 'max:'.config('checkout.cart.gift_message_max', 500)],
        ];
    }

    public function attributes(): array
    {
        return ['gift_message' => __('validation.attributes.gift_message')];
    }
}
