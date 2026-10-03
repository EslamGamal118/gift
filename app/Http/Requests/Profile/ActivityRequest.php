<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ActivityRequest extends ProfileStepRequest
{
    protected array $allowedRoles = [User::TYPE_CAPTAIN, User::TYPE_SHOPPER];

    protected function prepareStep(): void
    {
        $merge = [];

        if ($this->filled('plate_number')) {
            $merge['plate_number'] = strtoupper(trim(preg_replace('/\s+/', ' ', $this->input('plate_number'))));
        }
        $ids = $this->input('category_ids');
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $merge['category_ids'] = is_array($decoded)
                ? $decoded
                : array_values(array_filter(array_map('trim', explode(',', $ids)), 'strlen'));
        }

        if ($this->filled('national_id_number')) {
            $merge['national_id_number'] = preg_replace('/\D+/', '', $this->input('national_id_number'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->user()->isCaptain()
            ? $this->captainRules()
            : $this->shopperRules();
    }

    protected function captainRules(): array
    {
        $profile = $this->profile();

        return [
            'account_type'    => $this->accountTypeRule(),
            'vehicle_type_id' => ['required', 'integer', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'vehicle_model'   => ['required', 'string', 'min:2', 'max:100'],
            'plate_number'    => ['required', 'string', 'min:3', 'max:20', 'regex:/^[\p{L}\p{N} \-]+$/u'],
            'driving_license' => [
                Rule::requiredIf(fn () => blank($profile->license_file)),
                'nullable', ...self::DOCUMENT_RULES,
            ],
            'plate_image'     => [
                Rule::requiredIf(fn () => blank($profile->plate_number_file)),
                'nullable', ...self::DOCUMENT_RULES,
            ],
            'vehicle_image'   => ['nullable', ...self::IMAGE_RULES],
            'commercial_register'   => ['nullable', ...self::DOCUMENT_RULES],
            'freelance_certificate' => ['nullable', ...self::DOCUMENT_RULES],
        ];
    }

    protected function shopperRules(): array
    {
        $profile = $this->profile();

        return [
            'account_type'       => $this->accountTypeRule(),
            'category_ids'       => ['required', 'array', 'min:1', 'max:10'],
            'category_ids.*'     => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'national_id_number' => ['nullable', 'digits:10'],
            'national_id'        => [
                Rule::requiredIf(fn () => blank($profile->national_id_file)),
                'nullable', ...self::DOCUMENT_RULES,
            ],
            'driving_license'    => ['nullable', ...self::DOCUMENT_RULES],
            'freelance_certificate' => [
                Rule::requiredIf(fn () => blank($profile->freelance_license_file)),
                'nullable', ...self::DOCUMENT_RULES,
            ],
        ];
    }

    public function commercialRegisterFile(): ?UploadedFile
    {
        return $this->file('commercial_register') ?? $this->file('freelance_certificate');
    }

    public function attributes(): array
    {
        return [
            'account_type'          => __('validation.attributes.account_type'),
            'vehicle_type_id'       => __('validation.attributes.vehicle_type_id'),
            'vehicle_model'         => __('validation.attributes.vehicle_model'),
            'plate_number'          => __('validation.attributes.plate_number'),
            'driving_license'       => __('validation.attributes.driving_license'),
            'plate_image'           => __('validation.attributes.plate_image'),
            'vehicle_image'         => __('validation.attributes.vehicle_image'),
            'commercial_register'   => __('validation.attributes.commercial_register'),
            'freelance_certificate' => __('validation.attributes.freelance_certificate'),
            'category_ids'          => __('validation.attributes.category_ids'),
            'category_ids.*'        => __('validation.attributes.category_id'),
            'national_id_number'    => __('validation.attributes.national_id_number'),
            'national_id'           => __('validation.attributes.national_id'),
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'plate_number.regex' => __('profile.invalid_plate_number'),
        ];
    }
}
