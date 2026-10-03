<?php

namespace App\Http\Requests\Checkout;

use App\Http\Requests\Checkout\Concerns\ValidatesDeliverySelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pay for the cart. The address / delivery / promo selections are read from
 * the cart; the request may still override the address, delivery and gift
 * message in one shot, and names the gateway the payment is opened with.
 */
class PlaceOrderRequest extends FormRequest
{
    use ValidatesDeliverySelection;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseDeliveryType();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'integer'],
            'gift_message' => ['nullable', 'string', 'max:'.config('checkout.cart.gift_message_max', 500)],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', Rule::in(config('checkout.orders.payment_methods', []))],
            // Optional: same fields as POST /checkout/delivery; omitted = use the cart's saved choice
            ...$this->deliveryRules(required: false),
        ];
    }

    public function paymentMethod(): string
    {
        return (string) $this->validated('payment_method');
    }

    public function attributes(): array
    {
        return [
            'address_id' => __('validation.attributes.address_id'),
            'gift_message' => __('validation.attributes.gift_message'),
            'promo_code' => __('validation.attributes.promo_code'),
            'payment_method' => __('validation.attributes.payment_method'),
        ] + $this->deliveryAttributes();
    }
}
