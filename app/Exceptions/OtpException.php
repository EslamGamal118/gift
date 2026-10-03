<?php

namespace App\Exceptions;

/**
 * أخطاء رمز التحقق. تُحوَّل تلقائيًا إلى استجابة API موحّدة في Handler.
 */
class OtpException extends ApiException
{
    public static function invalid(): self
    {
        return new self('auth.invalid_otp', 422);
    }

    public static function expired(): self
    {
        return new self('auth.otp_expired', 422);
    }

    public static function alreadyUsed(): self
    {
        return new self('auth.otp_already_used', 422);
    }

    public static function tooManyAttempts(int $retryAfter): self
    {
        return new self('auth.too_many_attempts', 429, ['retry_after' => $retryAfter]);
    }

    public static function cooldown(int $retryAfter): self
    {
        return new self('auth.otp_cooldown', 429, ['retry_after' => $retryAfter]);
    }

    public static function sendFailed(): self
    {
        return new self('auth.otp_send_failed', 502);
    }
}
