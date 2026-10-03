<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiatePaymentRequest extends FormRequest
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
            'gateway' => ['required', Rule::in(config('checkout.orders.payment_methods', []))],
        ];
    }

    public function gateway(): string
    {
        return (string) $this->validated('gateway');
    }

    public function attributes(): array
    {
        return ['gateway' => __('validation.attributes.payment_method')];
    }
}
