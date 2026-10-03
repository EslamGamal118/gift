<?php

namespace App\Http\Requests\Gift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /gifts/claim { code }: the 6-digit code texted to the recipient.
 * Arabic-Indic / Persian digits and spaces are accepted ("١٢٣ ٤٥٦" = "123456").
 */
class ClaimGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('code'))) {
            return;
        }

        $code = strtr($this->input('code'), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $this->merge(['code' => preg_replace('/[\s\-]+/u', '', $code)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    public function attributes(): array
    {
        return ['code' => __('gifts.claim.code_attribute')];
    }
}
