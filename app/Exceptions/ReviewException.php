<?php

namespace App\Exceptions;

class ReviewException extends ApiException
{
    /**
     * Only a delivered store order / a completed custom order can be reviewed.
     */
    public static function notReviewable(string $status): self
    {
        return new self('reviews.not_reviewable', 409, ['current_status' => $status]);
    }

    public static function alreadyReviewed(): self
    {
        return new self('reviews.already_reviewed', 409);
    }
}
