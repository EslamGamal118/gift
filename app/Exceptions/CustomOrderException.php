<?php

namespace App\Exceptions;

/**
 * Custom order actions that cannot be carried out in the order's current
 * state or with the chosen shopper.
 */
class CustomOrderException extends ApiException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self('custom_orders.invalid_transition', 409, [
            'current_status' => $from,
            'target_status' => $to,
        ], [
            'from' => __('custom_orders.statuses.'.$from),
            'to' => __('custom_orders.statuses.'.$to),
        ]);
    }

    public static function notAssignable(string $status): self
    {
        return new self('custom_orders.not_assignable', 409, ['current_status' => $status], [
            'status' => __('custom_orders.statuses.'.$status),
        ]);
    }

    public static function shopperUnavailable(): self
    {
        return new self('custom_orders.shopper_unavailable', 422);
    }

    public static function addressRequired(): self
    {
        return new self('custom_orders.address_required', 422);
    }

    public static function addressNotFound(): self
    {
        return new self('custom_orders.address_not_found', 422);
    }

    public static function notConfirmable(string $status): self
    {
        return new self('custom_orders.not_confirmable', 409, ['current_status' => $status], [
            'status' => __('custom_orders.statuses.'.$status),
        ]);
    }

    public static function shopperNotChosen(): self
    {
        return new self('custom_orders.shopper_not_chosen', 409);
    }

    public static function slotUnavailable(): self
    {
        return new self('custom_orders.slot_unavailable', 422);
    }

    /**
     * Alternatives can only be suggested while the shopper is working on the
     * order (accepted / in progress).
     */
    public static function alternativesClosed(string $status): self
    {
        return new self('custom_orders.alternatives_closed', 409, ['current_status' => $status], [
            'status' => __('custom_orders.statuses.'.$status),
        ]);
    }

    /**
     * The invoice is submitted once shopping has started (in progress or completed).
     */
    public static function invoiceClosed(string $status): self
    {
        return new self('custom_orders.invoice_closed', 409, ['current_status' => $status], [
            'status' => __('custom_orders.statuses.'.$status),
        ]);
    }

    /**
     * The customer pays once the shopper has submitted the invoice (every item priced).
     */
    public static function notPayableYet(): self
    {
        return new self('custom_orders.not_payable_yet', 409);
    }

    /**
     * The customer already paid: the invoice and prices are final.
     */
    public static function alreadyPaid(): self
    {
        return new self('custom_orders.already_paid', 409);
    }

    /**
     * The customer answers alternatives only while the order is being shopped.
     */
    public static function alternativesNotOpen(string $status): self
    {
        return new self('custom_orders.alternatives_not_open', 409, ['current_status' => $status], [
            'status' => __('custom_orders.statuses.'.$status),
        ]);
    }

    public static function notCancellable(string $status): self
    {
        return new self('custom_orders.not_cancellable', 409, ['current_status' => $status], [
            'status' => __('custom_orders.statuses.'.$status),
        ]);
    }
}
