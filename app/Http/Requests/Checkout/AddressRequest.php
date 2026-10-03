<?php

namespace App\Http\Requests\Checkout;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update a delivery address. On PUT every field is optional so
 * partial updates are allowed.
 */
class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['location_name', 'city', 'district', 'street', 'building_number'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }

        if ($this->filled('phone')) {
            $merge['phone'] = PhoneNumber::normalize($this->input('phone'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';

        return [
            'location_name' => [$required, 'string', 'min:2', 'max:100'],
            'city' => [$required, 'string', 'min:2', 'max:100'],
            'district' => [$required, 'string', 'min:2', 'max:100'],
            'street' => [$required, 'string', 'min:2', 'max:150'],
            'building_number' => [$required, 'string', 'min:1', 'max:30'],
            'phone' => ['nullable', 'string', 'regex:'.config('otp.phone_pattern')],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function isUpdate(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    public function attributes(): array
    {
        return [
            'location_name' => __('validation.attributes.location_name'),
            'city' => __('validation.attributes.city'),
            'district' => __('validation.attributes.district'),
            'street' => __('validation.attributes.street'),
            'building_number' => __('validation.attributes.building_number'),
            'phone' => __('validation.attributes.phone'),
            'latitude' => __('validation.attributes.latitude'),
            'longitude' => __('validation.attributes.longitude'),
            'is_default' => __('validation.attributes.is_default'),
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => __('auth.invalid_phone'),
        ];
    }
}
