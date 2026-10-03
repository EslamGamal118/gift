<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\ResolvesOtpPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class VerifyOtpRequest extends FormRequest
{
    use ResolvesOtpPayload;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeOtpPayload();

        $this->merge([
            'otp' => preg_replace('/\D+/', '', (string) $this->input('otp')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->otpPayloadRules(), [
            'name'        => ['nullable', 'string', 'max:100'],
            'otp'         => ['required', 'string', 'digits_between:4,6'],
            'fcm_token'   => ['nullable', 'string', 'max:4096'],
            'device_type' => ['nullable', 'string', Rule::in(['android', 'ios', 'web'])],
            'device_id'   => ['nullable', 'string', 'max:191'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
    }

    public function attributes(): array
    {
        return array_merge($this->otpPayloadAttributes(), [
            'otp'       => __('auth.attributes.otp'),
            'fcm_token' => __('auth.attributes.fcm_token'),
        ]);
    }

    public function messages(): array
    {
        return $this->otpPayloadMessages();
    }

    public function otp(): string
    {
        return $this->validated('otp');
    }
}
