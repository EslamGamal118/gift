<?php

namespace App\Http\Requests\Store;

use App\Exceptions\ProfileStepException;
use App\Models\StoreProfile;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / update a store branch. Used for both POST and PUT; on PUT every field
 * is optional ("sometimes") so partial updates are allowed.
 */
class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Branch management requires the store profile created in the basic-info step.
        if (! $this->storeProfile()) {
            throw ProfileStepException::basicInfoRequired();
        }

        $merge = [];

        if ($this->filled('phone')) {
            $merge['phone'] = PhoneNumber::normalize($this->input('phone'));
        }

        if ($this->filled('name')) {
            $merge['name'] = trim($this->input('name'));
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
            'name'      => [$required, 'string', 'min:2', 'max:100'],
            'address'   => [$required, 'string', 'min:3', 'max:500'],
            'latitude'  => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'phone'     => ['nullable', 'string', 'regex:'.config('otp.phone_pattern')],
            'is_main'   => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function isUpdate(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    public function storeProfile(): ?StoreProfile
    {
        return $this->user()?->storeProfile;
    }

    public function attributes(): array
    {
        return [
            'name'      => __('validation.attributes.branch_name'),
            'address'   => __('validation.attributes.address'),
            'latitude'  => __('validation.attributes.latitude'),
            'longitude' => __('validation.attributes.longitude'),
            'phone'     => __('validation.attributes.phone'),
            'is_main'   => __('validation.attributes.is_main'),
            'is_active' => __('validation.attributes.is_active'),
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => __('auth.invalid_phone'),
        ];
    }
}
