<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * التحقق من رقم آيبان سعودي: SA + 22 رقمًا (24 خانة) مع فحص MOD-97 وفق ISO 13616.
 */
class SaudiIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $value));

        if (! preg_match('/^SA\d{22}$/', $iban) || ! self::checksumIsValid($iban)) {
            $fail('profile.invalid_iban')->translate();
        }
    }

    /**
     * ISO 13616: انقل أول 4 خانات إلى النهاية، حوّل الأحرف إلى أرقام (A=10 … Z=35)، ثم mod 97 يجب أن يساوي 1.
     */
    public static function checksumIsValid(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // حساب mod 97 على أجزاء لتجنب تجاوز حدود الأعداد الصحيحة
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }
}
