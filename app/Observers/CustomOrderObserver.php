<?php

namespace App\Observers;

use App\Jobs\RefundCustomOrderPayment;
use App\Models\CustomOrder;
use App\Services\NotificationService;

/**
 * Payment side effects of a custom order's lifecycle, whatever changed it
 * (gateway webhook / callback, customer or shopper cancellation, admin):
 *
 *  - paid (and not cancelled)  -> the assigned shopper is told the customer paid;
 *  - cancelled while paid      -> the payment is refunded through its gateway
 *    (also when a late payment lands on an order already cancelled).
 *
 * Runs only after the change is committed, so a rolled-back cancellation
 * never refunds and a notification never announces an unsaved payment.
 */
class CustomOrderObserver
{
    public bool $afterCommit = true;

    public function __construct(protected NotificationService $notifications) {}

    public function updated(CustomOrder $order): void
    {
        $becamePaid = $order->wasChanged('payment_status') && $order->isPaid();

        if ($becamePaid && $order->status !== CustomOrder::STATUS_CANCELLED) {
            $this->notifications->notifyShopperOrderPaid($order);
        }

        $becameCancelled = $order->wasChanged('status') && $order->status === CustomOrder::STATUS_CANCELLED;

        if ($order->isPaid() && $order->status === CustomOrder::STATUS_CANCELLED && ($becamePaid || $becameCancelled)) {
            RefundCustomOrderPayment::dispatch($order->id);
        }
    }
}
