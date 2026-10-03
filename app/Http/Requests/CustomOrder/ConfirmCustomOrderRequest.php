<?php

namespace App\Http\Requests\CustomOrder;

use App\Http\Requests\CustomOrder\Concerns\ValidatesDeliveryAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 3: confirm a draft custom order with its delivery details.
 *
 * POST /custom-orders/{id}/confirm
 *
 *   address_id | address[...]     where to deliver (see ValidatesDeliveryAddress)
 *   delivery_at                   "Y-m-d H:i" exact time within the lead window  - or -
 *   delivery_date + delivery_slot_id   a day ("Y-m-d") and one of the delivery slots
 *   confirmation_notes            delivery instructions for the shopper
 *
 * Slot availability (lead time / scheduling window) is checked by the service.
 */
class ConfirmCustomOrderRequest extends FormRequest
{
    use ValidatesDeliveryAddress;

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
        foreach (['delivery_at', 'delivery_date', 'delivery_slot_id'] as $field) {
            if ($this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $delivery = config('custom_orders.delivery');

        $earliest = now()->addMinutes((int) $delivery['min_lead_minutes'])->format('Y-m-d H:i:s');
        $latest   = now()->addDays((int) $delivery['max_lead_days'])->format('Y-m-d H:i:s');

        return $this->addressRules(required: true) + [
            // Delivery schedule: an exact time, or a date + slot
            'delivery_at'      => ['nullable', 'required_without:delivery_slot_id', 'date_format:Y-m-d H:i', 'after_or_equal:'.$earliest, 'before_or_equal:'.$latest],
            'delivery_date'    => ['nullable', 'required_with:delivery_slot_id', 'date_format:Y-m-d'],
            'delivery_slot_id' => ['nullable', 'required_without:delivery_at', 'integer', Rule::exists('delivery_slots', 'id')->where('is_active', true)],

            'confirmation_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Validated delivery details, ready for the service.
     *
     * @return array<string, mixed>
     */
    public function deliveryData(): array
    {
        return $this->validated();
    }

    public function attributes(): array
    {
        $keys = [...$this->addressAttributeKeys(), 'delivery_at', 'delivery_date', 'delivery_slot_id', 'confirmation_notes'];

        return array_combine($keys, array_map(fn ($k) => __('validation.attributes.'.$k), $keys));
    }

    public function messages(): array
    {
        $delivery = config('custom_orders.delivery');

        return $this->addressMessages() + [
            'address_id.required_without'       => __('custom_orders.address_required'),
            'address.required_without'          => __('custom_orders.address_required'),
            'delivery_at.required_without'      => __('custom_orders.delivery_required'),
            'delivery_slot_id.required_without' => __('custom_orders.delivery_required'),
            'delivery_at.after_or_equal'        => __('custom_orders.delivery_too_soon', ['minutes' => $delivery['min_lead_minutes']]),
            'delivery_at.before_or_equal'       => __('custom_orders.delivery_too_far', ['days' => $delivery['max_lead_days']]),
        ];
    }
}
