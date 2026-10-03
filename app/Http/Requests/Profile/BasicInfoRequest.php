<?php

namespace App\Http\Requests\Profile;

use App\Exceptions\ProfileLockedException;
use App\Models\User;
use App\Rules\SaudiIban;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BasicInfoRequest extends FormRequest
{
    public const MAX_IMAGE_KB    = 2048;
    public const MAX_DOCUMENT_KB = 5120;

    protected const IMAGE_RULES    = ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KB];
    protected const DOCUMENT_RULES = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::MAX_DOCUMENT_KB];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

  
    protected function prepareForValidation(): void
    {
        $profile = $this->existingProfile();

        if ($profile && in_array($profile->status, ['pending', 'approved'], true)) {
            throw ProfileLockedException::forStatus($profile->status);
        }
        $role = $this->filled('account_type') ? $this->input('account_type') : $this->input('role');

        $this->merge(array_filter([
            'account_type' => is_string($role) ? strtolower(trim($role)) : $role,
            'phone'        => $this->filled('phone') ? PhoneNumber::normalize($this->input('phone')) : null,
            'email'        => $this->filled('email') ? strtolower(trim($this->input('email'))) : null,
            'iban'         => $this->filled('iban') ? strtoupper(preg_replace('/\s+/', '', $this->input('iban'))) : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user    = $this->user();
        $role    = $this->role();
        $profile = $this->existingProfile();

        $rules = [
            'account_type' => ['required', 'string', Rule::in(array_keys(User::ROLE_ALIASES))],
            'name'         => ['required', 'string', 'min:2', 'max:100'],
            'phone'        => ['nullable', 'string', 'regex:'.config('otp.phone_pattern'), Rule::in([$user->phone])],
            'email'        => [
                'required', 'string', 'email:rfc', 'max:191',
                // فريد بين حسابات نفس الدور (يتجاهل الحساب الحالي)
                Rule::unique('users', 'email')
                    ->where('user_type', $user->user_type)
                    ->ignore($user->id),
            ],
            'iban'         => ['required', 'string'],
            'iban_certificate' => [
                Rule::requiredIf(fn () => blank($profile?->iban_certificate_file)),
                'nullable', ...self::DOCUMENT_RULES,
            ],
            'avatar'        => ['nullable', ...self::IMAGE_RULES],
            'profile_image' => ['nullable', ...self::IMAGE_RULES],
        ];

        // بيانات المتجر (الاسم، جوال وبريد خدمة العملاء) تُرسل في خطوة تفاصيل المتجر فقط
        return match ($role) {
            User::TYPE_CAPTAIN, User::TYPE_SHOPPER => $this->rulesForIndividual($rules, $user, $profile),
            default                                => $rules,
        };
    }

    /**
     * الكابتن والمتسوق: الصورة الشخصية مطلوبة أول مرة.
     */
    protected function rulesForIndividual(array $rules, User $user, $profile): array
    {
        $needsPhoto = blank($profile?->personal_photo) && blank($user->avatar);

        $rules['avatar'] = [
            Rule::requiredIf(fn () => $needsPhoto && ! $this->hasFile('profile_image')),
            'nullable', ...self::IMAGE_RULES,
        ];

        return $rules;
    }

    /**
     * الدور المطلوب يجب أن يطابق دور الحساب المسجّل، وأن يكون دورًا يملك بروفايلًا.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('account_type')) {
                return;
            }

            $user = $this->user();

            if (! $user->requiresProfile()) {
                $validator->errors()->add('account_type', __('profile.not_applicable'));

                return;
            }

            if ($this->role() !== $user->user_type) {
                $validator->errors()->add('account_type', __('profile.account_type_mismatch', [
                    'registered_as' => $user->user_type,
                ]));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'account_type'     => __('validation.attributes.account_type'),
            'name'             => __('validation.attributes.name'),
            'phone'            => __('validation.attributes.phone'),
            'email'            => __('validation.attributes.email'),
            'iban'             => __('validation.attributes.iban'),
            'iban_certificate' => __('validation.attributes.iban_certificate'),
            'avatar'           => __('validation.attributes.avatar'),
            'profile_image'    => __('validation.attributes.profile_image'),
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'     => __('auth.invalid_phone'),
            'phone.in'        => __('profile.phone_must_match'),
            'account_type.in' => __('auth.invalid_account_type'),
        ];
    }

    /**
     * الدور بالقيمة المخزّنة (customer / store / captain / shopper) أو null إن كان غير معروف.
     */
    public function role(): ?string
    {
        return User::normalizeRole($this->input('account_type'));
    }

    /**
     * البروفايل الحالي للمستخدم (إن وُجد) — يُستخدم لجعل الملفات اختيارية عند التعديل.
     */
    public function existingProfile(): mixed
    {
        return $this->user()?->profile();
    }

    /**
     * الصورة الشخصية المرفوعة (avatar أو مرادفه profile_image)
     */
    public function avatarFile(): ?UploadedFile
    {
        return $this->file('avatar') ?? $this->file('profile_image');
    }

    public function ibanCertificateFile(): ?UploadedFile
    {
        return $this->file('iban_certificate');
    }
}
