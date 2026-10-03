<?php

namespace App\Http\Requests\Profile;

use App\Exceptions\ProfileLockedException;
use App\Exceptions\ProfileStepException;
use App\Http\Requests\Profile\Concerns\ValidatesWorkingHours;
use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Unified partial profile update for every account type.
 *
 * Every field is optional; only the fields present in the request are validated and saved.
 * The accepted fields depend on the authenticated account's role:
 *
 *  - all roles      : name, email, avatar|profile_image  (the account owner; the verified
 *                     mobile is changed through OTP only)
 *  - store/captain/shopper : iban, iban_certificate
 *  - store          : store_name, store_phone, store_email (customer-facing store contact),
 *                     category_id, description, logo, cover_image|banner,
 *                     commercial_register|freelance_certificate, working_hours,
 *                     address, latitude, longitude (saved on the main branch)
 *  - captain        : vehicle_type_id, vehicle_model, plate_number, driving_license, plate_image,
 *                     vehicle_image, commercial_register|freelance_certificate, address, latitude, longitude
 *  - shopper        : category_ids, national_id_number, national_id, driving_license,
 *                     freelance_certificate, address, latitude, longitude
 *
 * A profile under review cannot be edited (409). Roles that require a profile must have
 * completed the basic-info step first (409).
 */
class ProfileUpdateRequest extends FormRequest
{
    use ValidatesWorkingHours;

    public const MAX_IMAGE_KB    = 2048;
    public const MAX_COVER_KB    = 4096;
    public const MAX_DOCUMENT_KB = 5120;

    protected const IMAGE_RULES    = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KB];
    protected const COVER_RULES    = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_COVER_KB];
    protected const DOCUMENT_RULES = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::MAX_DOCUMENT_KB];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $user    = $this->user();
        $profile = $this->existingProfile();

        if ($user->requiresProfile()) {
            if (! $profile) {
                throw ProfileStepException::basicInfoRequired();
            }

            if ($profile->status === 'pending') {
                throw ProfileLockedException::forStatus($profile->status);
            }
        }

        $merge = $this->decodeWorkingHours();

        $trimmed = ['name', 'store_name', 'address', 'description', 'vehicle_model'];
        foreach ($trimmed as $field) {
            if ($this->filled($field)) {
                $merge[$field] = trim($this->input($field));
            }
        }

        if ($this->filled('email')) {
            $merge['email'] = strtolower(trim($this->input('email')));
        }

        if ($this->filled('store_email')) {
            $merge['store_email'] = strtolower(trim($this->input('store_email')));
        }

        if ($this->filled('store_phone')) {
            $merge['store_phone'] = PhoneNumber::normalize($this->input('store_phone'));
        }

        if ($this->filled('iban')) {
            $merge['iban'] = strtoupper(preg_replace('/\s+/', '', $this->input('iban')));
        }

        if ($this->filled('plate_number')) {
            $merge['plate_number'] = strtoupper(trim(preg_replace('/\s+/', ' ', $this->input('plate_number'))));
        }

        if ($this->filled('national_id_number')) {
            $merge['national_id_number'] = preg_replace('/\D+/', '', $this->input('national_id_number'));
        }

        $ids = $this->input('category_ids');
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $merge['category_ids'] = is_array($decoded)
                ? $decoded
                : array_values(array_filter(array_map('trim', explode(',', $ids)), 'strlen'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        $rules = [
            'name'          => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email'         => [
                'sometimes', 'required', 'string', 'email:rfc', 'max:191',
                Rule::unique('users', 'email')->where('user_type', $user->user_type)->ignore($user->id),
            ],
            'avatar'        => self::IMAGE_RULES,
            'profile_image' => self::IMAGE_RULES,
        ];

        if ($user->requiresProfile()) {
            $rules += [
                'iban'             => ['sometimes', 'required', 'string', 'max:34'],
                'iban_certificate' => self::DOCUMENT_RULES,
            ];
        }

        return array_merge($rules, match ($user->user_type) {
            User::TYPE_STORE   => $this->storeRules(),
            User::TYPE_CAPTAIN => $this->captainRules(),
            User::TYPE_SHOPPER => $this->shopperRules(),
            default            => [],
        });
    }

    protected function storeRules(): array
    {
        /** @var StoreProfile $profile */
        $profile = $this->existingProfile();

        return [
            'store_name'            => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'store_phone'           => ['sometimes', 'required', 'string', 'regex:'.config('otp.phone_pattern')],
            'store_email'           => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:191', Rule::unique('store_profiles', 'email')->ignore($profile?->id)],
            'category_id'           => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'description'           => ['sometimes', 'nullable', 'string', 'max:1000'],
            'logo'                  => self::IMAGE_RULES,
            'cover_image'           => self::COVER_RULES,
            'banner'                => self::COVER_RULES,
            'commercial_register'   => self::DOCUMENT_RULES,
            'freelance_certificate' => self::DOCUMENT_RULES,
        ] + $this->workingHoursRules('sometimes') + $this->locationRules();
    }

    protected function captainRules(): array
    {
        return [
            'vehicle_type_id'       => ['sometimes', 'required', 'integer', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'vehicle_model'         => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'plate_number'          => ['sometimes', 'required', 'string', 'min:3', 'max:20', 'regex:/^[\p{L}\p{N} \-]+$/u'],
            'driving_license'       => self::DOCUMENT_RULES,
            'plate_image'           => self::DOCUMENT_RULES,
            'vehicle_image'         => self::IMAGE_RULES,
            'commercial_register'   => self::DOCUMENT_RULES,
            'freelance_certificate' => self::DOCUMENT_RULES,
        ] + $this->locationRules();
    }

    protected function shopperRules(): array
    {
        return [
            'category_ids'          => ['sometimes', 'required', 'array', 'min:1', 'max:10'],
            'category_ids.*'        => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'national_id_number'    => ['sometimes', 'nullable', 'digits:10'],
            'national_id'           => self::DOCUMENT_RULES,
            'driving_license'       => self::DOCUMENT_RULES,
            'freelance_certificate' => self::DOCUMENT_RULES,
        ] + $this->locationRules();
    }

    /**
     * Coordinates must be updated together with the address.
     */
    protected function locationRules(): array
    {
        return [
            'address'   => ['nullable', 'required_with:latitude,longitude', 'string', 'min:3', 'max:500'],
            'latitude'  => ['nullable', 'required_with:address,longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:address,latitude', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->user()->isStore()) {
            $validator->after(fn (Validator $validator) => $this->validateWorkingHours($validator));
        }
    }

    public function existingProfile(): StoreProfile|CaptainProfile|ShopperProfile|null
    {
        return $this->user()?->profile();
    }

    public function avatarFile(): ?UploadedFile
    {
        return $this->file('avatar') ?? $this->file('profile_image');
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
        $keys = [
            'name', 'email', 'store_phone', 'store_email', 'avatar', 'profile_image', 'iban', 'iban_certificate',
            'store_name', 'category_id', 'description', 'logo', 'cover_image', 'commercial_register', 'freelance_certificate',
            'vehicle_type_id', 'vehicle_model', 'plate_number', 'driving_license', 'plate_image', 'vehicle_image',
            'category_ids', 'national_id_number', 'national_id', 'address', 'latitude', 'longitude',
        ];

        $attributes = [];
        foreach ($keys as $key) {
            $attributes[$key] = __('validation.attributes.'.$key);
        }

        $attributes['banner']         = __('validation.attributes.cover_image');
        $attributes['category_ids.*'] = __('validation.attributes.category_id');

        return $attributes + $this->workingHoursAttributes();
    }

    public function messages(): array
    {
        return [
            'store_phone.regex'                 => __('auth.invalid_phone'),
            'plate_number.regex'                => __('profile.invalid_plate_number'),
            'working_hours.required_array_keys' => __('profile.working_hours_all_days'),
        ];
    }
}
