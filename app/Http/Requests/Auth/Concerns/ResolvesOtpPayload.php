<?php

namespace App\Http\Requests\Auth\Concerns;

use App\Models\User;
use App\Support\AuthAction;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

trait ResolvesOtpPayload
{
    protected function normalizeOtpPayload(): void
    {
        $countryCode = PhoneNumber::sanitizeCountryCode($this->input('country_code'));
        $mobile      = $this->filled('mobile') ? $this->input('mobile') : $this->input('phone');
        $userType    = $this->firstFilled(['user_type', 'account_type', 'role']);

        $this->merge([
            'country_code' => $countryCode ?? PhoneNumber::countryCode($mobile),
            'mobile'       => PhoneNumber::normalize($mobile, $countryCode ?? PhoneNumber::DEFAULT_COUNTRY_CODE),
            'action'       => $this->lowercased($this->input('action')),
            'user_type'    => $this->lowercased($userType),
            'name'         => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

   
    protected function otpPayloadRules(): array
    {
        return [
            'country_code' => ['nullable', 'string', 'digits_between:1,4'],
            'mobile'       => ['required', 'string', 'regex:'.config('otp.phone_pattern')],
            'action'       => ['required', 'string', Rule::in(AuthAction::ALL)],
            'user_type'    => ['required', 'string', Rule::in(array_keys(User::ROLE_ALIASES))],
            'name'         => [
                Rule::requiredIf(function () {
                    return $this->input('action') === AuthAction::REGISTER 
                        && User::normalizeRole($this->input('user_type')) === 'customer';
                }),
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function otpPayloadAttributes(): array
    {
        return [
            'country_code' => __('auth.attributes.country_code'),
            'mobile'       => __('auth.attributes.mobile'),
            'action'       => __('auth.attributes.action'),
            'user_type'    => __('auth.attributes.user_type'),
            'name'         => __('auth.attributes.name'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function otpPayloadMessages(): array
    {
        return [
            'mobile.regex'  => __('auth.invalid_phone'),
            'action.in'     => __('auth.invalid_action'),
            'user_type.in'  => __('auth.invalid_account_type'),
        ];
    }

    public function mobile(): string
    {
        return $this->validated('mobile');
    }

    public function countryCode(): string
    {
        return $this->validated('country_code') ?: PhoneNumber::DEFAULT_COUNTRY_CODE;
    }

    /**
     * login | register
     */
    public function action(): string
    {
        return $this->validated('action');
    }

    public function isRegistration(): bool
    {
        return $this->action() === AuthAction::REGISTER;
    }

    public function isLogin(): bool
    {
        return $this->action() === AuthAction::LOGIN;
    }

    /**
     * الدور بالقيمة المخزّنة في قاعدة البيانات (customer / store / captain / shopper)
     */
    public function role(): string
    {
        return User::normalizeRole($this->validated('user_type'));
    }

    public function name(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * أول حقل مُعبّأ من بين المرادفات.
     */
    protected function firstFilled(array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($this->filled($key)) {
                return $this->input($key);
            }
        }

        return null;
    }

    protected function lowercased(mixed $value): mixed
    {
        return is_string($value) ? strtolower(trim($value)) : $value;
    }
}