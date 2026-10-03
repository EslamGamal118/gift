<?php

namespace App\Exceptions;

/**
 * An order action that is not allowed from the order's current status.
 */
class OrderStateException extends ApiException
{
    public static function invalidTransition(string $action, string $currentStatus): self
    {
        return new self('orders.invalid_transition', 409, [
            'action' => $action,
            'current_status' => $currentStatus,
        ], [
            'action' => __('orders.actions.'.$action),
            'status' => __('orders.statuses.'.$currentStatus),
        ]);
    }

    public static function unknownAction(string $action): self
    {
        return new self('orders.unknown_action', 400, ['action' => $action], ['action' => $action]);
    }
}
