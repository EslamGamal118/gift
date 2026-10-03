<?php

namespace App\Http\Requests\Checkout;

use App\Services\CustomOrderCheckoutService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /checkout/pay
 * { order_id, payment_method: alrajhi | tabby | tamara (paymob / neoleap = alrajhi), delivery_address_id? }
 *
 * `order_id` is one of the customer's custom orders (404 otherwise). Amounts
 * are never read from the request: the order is priced on the server
 * (CustomOrderPricing). `delivery_address_id` optionally switches the
 * delivery address to another of the customer's saved addresses.
 * `gateway` is accepted for `payment_method` (as on POST /orders/{order}/pay).
 */
class PayCustomOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $method = strtolower(trim((string) ($this->input('payment_method') ?? $this->input('gateway'))));

        $this->merge([
            'payment_method' => CustomOrderCheckoutService::GATEWAY_ALIASES[$method] ?? $method,
        ]);

        if ($this->input('delivery_address_id') === '') {
            $this->merge(['delivery_address_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id'            => ['required', 'integer', 'min:1'],
            'payment_method'      => ['required', Rule::in(config('checkout.orders.payment_methods', []))],
            'delivery_address_id' => [
                'nullable', 'integer',
                Rule::exists('user_addresses', 'id')->where('user_id', $this->user()?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return ['delivery_address_id.exists' => __('custom_orders.address_not_found')];
    }

    public function orderId(): int
    {
        return (int) $this->validated('order_id');
    }

    public function gateway(): string
    {
        return (string) $this->validated('payment_method');
    }

    public function deliveryAddressId(): ?int
    {
        $id = $this->validated('delivery_address_id');

        return $id !== null ? (int) $id : null;
    }

    public function attributes(): array
    {
        return [
            'order_id'            => __('validation.attributes.order_id'),
            'payment_method'      => __('validation.attributes.payment_method'),
            'delivery_address_id' => __('validation.attributes.address_id'),
        ];
    }
}
