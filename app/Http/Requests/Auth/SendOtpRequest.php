<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\ResolvesOtpPayload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * طلب إرسال رمز التحقق.
 *
 * يحدّد العميل صراحةً نيّة الطلب (action = login | register) ونوع الحساب (user_type)
 * مع بيانات الجوال، ويُرسل الاسم عند التسجيل ليُخزَّن في طلب التوثيق
 * ثم يُستخدم في إنشاء الحساب بعد نجاح التحقق.
 */
class SendOtpRequest extends FormRequest
{
    use ResolvesOtpPayload;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeOtpPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->otpPayloadRules();
    }

    public function attributes(): array
    {
        return $this->otpPayloadAttributes();
    }

    public function messages(): array
    {
        return $this->otpPayloadMessages();
    }
}
