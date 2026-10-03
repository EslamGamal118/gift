<?php

namespace App\Exceptions;

/**
 * أخطاء تسلسل خطوات إكمال البروفايل (الدور غير مناسب، الخطوة الأولى غير مكتملة، ...).
 */
class ProfileStepException extends ApiException
{
    public static function roleNotAllowed(string $role): self
    {
        return new self('profile.role_not_allowed', 403, ['account_type' => $role]);
    }

    public static function basicInfoRequired(): self
    {
        return new self('profile.basic_info_required', 409);
    }

    /**
     * @param  array<string, array<int, string>>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self('profile.incomplete', 422, ['missing' => $missing]);
    }
}
