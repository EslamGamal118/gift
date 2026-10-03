<?php

namespace App\Services\Payments;

use App\Interfaces\Payable;
use App\Models\CheckoutGroup;
use App\Models\CustomOrder;
use App\Models\Order;

/**
 * Finds the payable behind a key carried through a gateway (return URL,
 * webhook reference, AlRajhi trackId): see Payable::paymentKey().
 * "CG-" keys are multi-store checkouts. Plain numbers are store orders, as before custom orders could be paid,
 * so payments started earlier still resolve.
 */
class PayableResolver
{
    public const CUSTOM_ORDER_PREFIX = 'CO-';

    public const CHECKOUT_GROUP_PREFIX = 'CG-';

    public function find(string|int|null $key): ?Payable
    {
        $key = trim((string) $key);

        if (preg_match('/^'.preg_quote(self::CUSTOM_ORDER_PREFIX, '/').'(\d+)$/i', $key, $match)) {
            return CustomOrder::query()->find((int) $match[1]);
        }

        if (preg_match('/^'.preg_quote(self::CHECKOUT_GROUP_PREFIX, '/').'(\d+)$/i', $key, $match)) {
            return CheckoutGroup::query()->find((int) $match[1]);
        }

        return ctype_digit($key) ? Order::query()->find((int) $key) : null;
    }
}
