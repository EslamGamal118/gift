<?php

namespace App\Exceptions;

use App\Models\Gift;

/**
 * A store scanning a gift QR code that cannot be redeemed (rendered through ApiResponse).
 */
class GiftRedemptionException extends ApiException
{
    /**
     * Unknown code, or a gift of another store.
     */
    public static function notFound(): self
    {
        return new self('gifts.redeem.not_found', 404);
    }

    public static function alreadyRedeemed(Gift $gift): self
    {
        return new self('gifts.redeem.already_redeemed', 409, ['redeemed_at' => $gift->redeemed_at?->toIso8601String()]);
    }

    public static function expired(Gift $gift): self
    {
        return new self('gifts.redeem.expired', 409, ['expired_at' => $gift->expires_at?->toIso8601String()]);
    }
}
