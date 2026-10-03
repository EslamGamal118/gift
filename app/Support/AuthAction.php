<?php

namespace App\Support;

/**
 * نيّة الطلب في مسار OTP: تسجيل دخول لحساب قائم أم إنشاء حساب جديد.
 * يحدّدها العميل صراحةً في send-otp و verify-otp.
 */
final class AuthAction
{
    public const LOGIN = 'login';

    public const REGISTER = 'register';

    /**
     * @var array<int, string>
     */
    public const ALL = [self::LOGIN, self::REGISTER];

    public static function isValid(?string $action): bool
    {
        return in_array($action, self::ALL, true);
    }
}
