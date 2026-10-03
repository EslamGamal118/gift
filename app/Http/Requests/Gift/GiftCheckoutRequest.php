<?php

namespace App\Http\Requests\Gift;

use App\Http\Requests\Gift\Concerns\ValidatesGiftSelection;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /gifts/checkout
 * { product_id, quantity?, addon_ids[]?, promo_code?, recipient_name, recipient_phone, recipient_email?, gift_message?, gateway: alrajhi|tamara|tabby }
 *
 * The product must belong to an active special (online gifts) category. The
 * selection is validated like GET /gifts/details/{product}, which shows its price.
 */
class GiftCheckoutRequest extends FormRequest
{
    use ValidatesGiftSelection;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'recipient_phone' => $this->filled('recipient_phone') ? PhoneNumber::normalize((string) $this->input('recipient_phone')) : null,
            'recipient_email' => $this->filled('recipient_email') ? strtolower(trim((string) $this->input('recipient_email'))) : null,
            'gateway' => $this->filled('gateway') ? strtolower((string) $this->input('gateway')) : null,
        ], fn ($value) => $value !== null));

        $this->prepareGiftSelection();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->giftSelectionRules() + [
            'recipient_name' => ['required', 'string', 'min:2', 'max:100'],
            'recipient_phone' => ['required', 'string', 'regex:'.config('otp.phone_pattern')],
            'recipient_email' => ['nullable', 'email', 'max:150'],
            'gift_message' => ['nullable', 'string', 'max:'.(int) config('gifts.message_max_length', 500)],
            'gateway' => ['required', Rule::in(config('checkout.orders.payment_methods', []))],
        ];
    }

    /**
     * @return array{product_id: int, quantity: int, addon_ids: array<int, int>, promo_code: ?string, recipient_name: string, recipient_phone: string, recipient_email: ?string, gift_message: ?string, gateway: string}
     */
    public function giftData(): array
    {
        return [
            'product_id' => (int) $this->validated('product_id'),
            'quantity' => $this->quantity(),
            'addon_ids' => $this->addonIds(),
            'promo_code' => $this->promoCode(),
            'recipient_name' => (string) $this->validated('recipient_name'),
            'recipient_phone' => (string) $this->validated('recipient_phone'),
            'recipient_email' => $this->validated('recipient_email'),
            'gift_message' => $this->validated('gift_message'),
            'gateway' => (string) $this->validated('gateway'),
        ];
    }

    public function attributes(): array
    {
        return $this->giftSelectionAttributes() + [
            'recipient_name' => __('validation.attributes.recipient_name'),
            'recipient_phone' => __('validation.attributes.recipient_phone'),
            'recipient_email' => __('validation.attributes.recipient_email'),
            'gift_message' => __('validation.attributes.gift_message'),
            'gateway' => __('validation.attributes.gateway'),
        ];
    }
}
