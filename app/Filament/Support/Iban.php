<?php

namespace App\Filament\Support;

use Closure;

/**
 * Saudi IBAN typed in the admin panel. Pasted IBANs often carry spaces or
 * lowercase letters, so the value is normalized before it is validated and saved.
 */
class Iban
{
    public static function normalize(mixed $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $iban));
    }

    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! preg_match('/^SA\d{22}$/', self::normalize($value))) {
                $fail(__('admin.validation.iban'));
            }
        };
    }
}
