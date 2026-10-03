<?php

namespace App\Exceptions;

use App\Models\Gift;

/**
 * "Claim a gift" with a code that cannot be used (rendered through ApiResponse).
 */
class GiftClaimException extends ApiException
{
    /**
     * Unknown code (or an unpaid gift's): one message, so codes cannot be probed.
     */
    public static function invalidCode(): self
    {
        return new self('gifts.claim.invalid_code', 422);
    }

    public static function alreadyClaimed(): self
    {
        return new self('gifts.claim.already_claimed', 409);
    }

    public static function ownGift(): self
    {
        return new self('gifts.claim.own_gift', 422);
    }

    public static function expired(Gift $gift): self
    {
        return new self('gifts.claim.expired', 409, ['expired_at' => $gift->expires_at?->toIso8601String()]);
    }
}
