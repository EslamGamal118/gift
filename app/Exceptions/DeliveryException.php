<?php

namespace App\Exceptions;

/**
 * A store order could not be handed to the delivery company (Alshrouq). The
 * order is left as it was (still `ready`), so the store can try again.
 */
class DeliveryException extends ApiException
{
    /**
     * Alshrouq refused the order or could not be reached.
     */
    public static function failed(string $reason): self
    {
        return new self('orders.delivery_failed', 422, ['reason' => $reason, 'retryable' => true]);
    }

    public static function storeLocationRequired(): self
    {
        return new self('orders.delivery_store_location_required', 422);
    }

    public static function customerLocationRequired(): self
    {
        return new self('orders.delivery_customer_location_required', 422);
    }
}
