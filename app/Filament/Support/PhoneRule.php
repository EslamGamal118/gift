<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Validation for a phone typed in the admin panel: it must be a valid Saudi
 * mobile once normalized (966 5XXXXXXXX) and free among accounts of the same
 * type (one number may own a customer and a store account at once).
 */
class PhoneRule
{
    public static function make(string $userType, ?int $ignoreUserId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($userType, $ignoreUserId): void {
            $phone = PhoneNumber::normalize((string) $value);

            if (! preg_match(config('otp.phone_pattern'), $phone)) {
                $fail(__('admin.validation.phone_invalid'));

                return;
            }

            $taken = User::ofType($userType)
                ->where('phone', $phone)
                ->when($ignoreUserId, fn (Builder $q) => $q->whereKeyNot($ignoreUserId))
                ->exists();

            if ($taken) {
                $fail(__('admin.validation.phone_taken'));
            }
        };
    }
}
