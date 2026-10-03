<?php

namespace App\Exceptions;

/**
 * البروفايل قيد المراجعة أو معتمد ولا يمكن تعديل بياناته عبر خطوات الإكمال.
 */
class ProfileLockedException extends ApiException
{
    public static function forStatus(string $status): self
    {
        return new self(
            $status === 'pending' ? 'profile.locked_pending' : 'profile.locked_approved',
            409,
            ['profile_status' => $status]
        );
    }
}
