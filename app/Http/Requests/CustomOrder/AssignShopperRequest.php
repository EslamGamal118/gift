<?php

namespace App\Http\Requests\CustomOrder;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choose how the order gets its shopper.
 *
 * POST /custom-orders/{id}/assign-shopper
 *   mode=direct  + shopper_id   -> assign that shopper
 *   mode=bidding                -> open the order for shopper offers
 *
 * `mode` defaults to `direct` when a shopper_id is sent and to `bidding` otherwise.
 */
class AssignShopperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $mode = $this->input('mode');

        if (! is_string($mode) || $mode === '') {
            $mode = $this->filled('shopper_id') ? CustomOrder::MODE_DIRECT : CustomOrder::MODE_BIDDING;
        }

        $this->merge(['mode' => strtolower(trim($mode))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode'       => ['required', Rule::in([CustomOrder::MODE_DIRECT, CustomOrder::MODE_BIDDING])],
            'shopper_id' => [
                Rule::requiredIf(fn () => $this->input('mode') === CustomOrder::MODE_DIRECT),
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('user_type', 'shopper'),
            ],
        ];
    }

    public function mode(): string
    {
        return $this->validated('mode');
    }

    public function shopperId(): ?int
    {
        $id = $this->validated('shopper_id');

        return $id !== null ? (int) $id : null;
    }

    public function attributes(): array
    {
        return [
            'mode'       => __('validation.attributes.mode'),
            'shopper_id' => __('validation.attributes.shopper_id'),
        ];
    }
}
