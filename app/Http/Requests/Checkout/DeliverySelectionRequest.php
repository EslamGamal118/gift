<?php

namespace App\Http\Requests\Checkout;

use App\Http\Requests\Checkout\Concerns\ValidatesDeliverySelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Save the customer's delivery choice: instant delivery, or a date + time slot.
 * Availability (lead time, store hours, scheduling window) is checked by
 * DeliverySchedulingService.
 */
class DeliverySelectionRequest extends FormRequest
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
        return $this->deliveryRules();
    }

    public function attributes(): array
    {
        return $this->deliveryAttributes();
    }
}
