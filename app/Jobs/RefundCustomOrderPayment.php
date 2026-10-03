<?php

namespace App\Jobs;

use App\Models\CustomOrder;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refund a cancelled custom order's payment through the gateway it was paid
 * with (see PaymentService::refundCustomOrderPayment).
 *
 * Tried once: a failed refund is left as `refund_failed` with the gateway's
 * answer in payment_transactions for someone to check, rather than retried
 * blindly (an AlRajhi refund has no idempotency key).
 */
class RefundCustomOrderPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $customOrderId) {}

    public function handle(PaymentService $payments): void
    {
        $order = CustomOrder::query()->find($this->customOrderId);

        if ($order) {
            $payments->refundCustomOrderPayment($order, 'Custom order '.$order->order_number.' cancelled');
        }
    }
}
