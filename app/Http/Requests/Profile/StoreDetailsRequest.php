<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Profile\Concerns\ValidatesWorkingHours;
use App\Models\StoreProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Store activity & details step (step 2 for stores): name, customer-service phone and
 * email, category, description, logo, cover image, commercial register / freelance
 * certificate and weekly working hours. Everything here is saved on `store_profiles`;
 * the owner's own name / email / verified mobile belong to step 1 (basic-info).
 */
class StoreDetailsRequest extends ProfileStepRequest
{
    use ValidatesWorkingHours;

    protected array $allowedRoles = [User::TYPE_STORE];

    protected function prepareStep(): void
    {
        $merge = $this->decodeWorkingHours();

        if ($this->filled('store_name')) {
            $merge['store_name'] = trim($this->input('store_name'));
        }

        // `store_phone` / `store_email` are aliases (the names used by /profile/update)
        $phone = $this->input('phone', $this->input('store_phone'));
        if (filled($phone)) {
            $merge['phone'] = PhoneNumber::normalize($phone);
        }

        $email = $this->input('email', $this->input('store_email'));
        if (filled($email)) {
            $merge['email'] = strtolower(trim($email));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var StoreProfile $profile */
        $profile = $this->profile();

        return [
            'account_type' => $this->accountTypeRule(),
            'store_name'   => ['required', 'string', 'min:2', 'max:100'],
            // Customer-service contact of the store, independent of the owner's verified mobile / email
            'phone'        => ['required', 'string', 'regex:'.config('otp.phone_pattern')],
            'email'        => ['nullable', 'string', 'email:rfc', 'max:191', Rule::unique('store_profiles', 'email')->ignore($profile->id)],
            'category_id'  => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'description'  => ['nullable', 'string', 'max:1000'],
            'logo'         => [
                Rule::requiredIf(fn () => blank($profile->logo)),
                'nullable', ...self::IMAGE_RULES,
            ],
            // `banner` is an alias for `cover_image`
            'cover_image'  => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_COVER_KB],
            'banner'       => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_COVER_KB],
            // `freelance_certificate` is an alias for `commercial_register`
            'commercial_register' => [
                Rule::requiredIf(fn () => blank($profile->commercial_register_file) && ! $this->hasFile('freelance_certificate')),
                'nullable', ...self::DOCUMENT_RULES,
            ],
            'freelance_certificate' => ['nullable', ...self::DOCUMENT_RULES],
        ] + $this->workingHoursRules('required');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateWorkingHours($validator));
    }

    public function logoFile(): ?UploadedFile
    {
        return $this->file('logo');
    }

    public function coverFile(): ?UploadedFile
    {
        return $this->file('cover_image') ?? $this->file('banner');
    }

    public function commercialRegisterFile(): ?UploadedFile
    {
        return $this->file('commercial_register') ?? $this->file('freelance_certificate');
    }

    public function attributes(): array
    {
        return [
            'account_type'          => __('validation.attributes.account_type'),
            'store_name'            => __('validation.attributes.store_name'),
            'phone'                 => __('validation.attributes.store_phone'),
            'email'                 => __('validation.attributes.store_email'),
            'category_id'           => __('validation.attributes.category_id'),
            'description'           => __('validation.attributes.description'),
            'logo'                  => __('validation.attributes.logo'),
            'cover_image'           => __('validation.attributes.cover_image'),
            'banner'                => __('validation.attributes.cover_image'),
            'commercial_register'   => __('validation.attributes.commercial_register'),
            'freelance_certificate' => __('validation.attributes.freelance_certificate'),
        ] + $this->workingHoursAttributes();
    }

    public function messages(): array
    {
        return parent::messages() + [
            'phone.regex'                       => __('auth.invalid_phone'),
            'working_hours.required_array_keys' => __('profile.working_hours_all_days'),
        ];
    }
}
