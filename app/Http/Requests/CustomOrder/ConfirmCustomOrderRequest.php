<?php

namespace App\Http\Requests\CustomOrder;

use App\Http\Requests\Checkout\Concerns\ValidatesDeliverySelection;
use App\Http\Requests\CustomOrder\Concerns\ValidatesDeliveryAddress;
use App\Models\Cart;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 3: confirm a draft custom order with its delivery details.
 *
 * POST /custom-orders/{id}/confirm
 *
 *   address_id | address[...]     where to deliver (see ValidatesDeliveryAddress)
 *   delivery_type                 instant | scheduled (or `instant: true`), as in POST /checkout/place-order
 *   delivery_date + delivery_slot_id   a day ("Y-m-d") and one of the delivery slots (scheduled only)
 *   confirmation_notes            delivery instructions for the shopper
 *
 * The delivery fields follow the checkout rules (ValidatesDeliverySelection).
 * Availability (instant enabled, the slot inside the lead window) is checked
 * by the service.
 */
class ConfirmCustomOrderRequest extends FormRequest
{
    use ValidatesDeliveryAddress, ValidatesDeliverySelection;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = $this->normalizedAddressInput();

        if (is_string($this->input('confirmation_notes'))) {
            $merge['confirmation_notes'] = trim($this->input('confirmation_notes')) ?: null;
        }

        // Blank values from forms mean "not chosen"
        foreach (['delivery_date', 'delivery_slot_id'] as $field) {
            if ($this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
        $this->normaliseDeliveryType();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->addressRules(required: true) + $this->deliveryRules(required: true) + [
            'confirmation_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Validated delivery details, ready for the service. A slot sent with
     * instant delivery is ignored.
     *
     * @return array<string, mixed>
     */
    public function deliveryData(): array
    {
        $data = $this->validated();

        if ($this->deliveryType() !== Cart::DELIVERY_SCHEDULED) {
            $data['delivery_date'] = $data['delivery_slot_id'] = null;
        }

        return $data;
    }

    public function attributes(): array
    {
        $keys = [...$this->addressAttributeKeys(), 'confirmation_notes'];

        return array_combine($keys, array_map(fn ($k) => __('validation.attributes.'.$k), $keys)) + $this->deliveryAttributes();
    }

    public function messages(): array
    {
        return $this->addressMessages() + [
            'address_id.required_without' => __('custom_orders.address_required'),
            'address.required_without'    => __('custom_orders.address_required'),
        ];
    }
}
