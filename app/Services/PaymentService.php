<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Interfaces\Payable;
use App\Models\CheckoutGroup;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gateway-agnostic payment state transitions for anything payable (a store
 * order or a custom order). Every gateway (AlRajhi, Tamara, Tabby) funnels its
 * callbacks / webhooks through here so the payable is updated exactly once
 * and every interaction is logged in `payment_transactions`.
 */
class PaymentService
{
    public function __construct(
        protected CheckoutService $checkout,
        protected NotificationService $notifications,
        protected GiftService $gifts,
    ) {}

    /**
     * Mark the payable as paid (a store order is also confirmed). Idempotent:
     * a second call for an already-paid payable is a no-op that still returns true.
     *
     * @param  array<string, mixed>  $details  Raw gateway data kept for audit
     */
    public function completeOrderPayment(Payable $order, array $details = [], ?string $gateway = null, ?string $reference = null): bool
    {
        $justPaid = DB::transaction(function () use ($order, $details, $gateway, $reference) {
            /** @var Payable&\Illuminate\Database\Eloquent\Model $order */
            $order = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->isPaid()) {
                return null; // already handled earlier
            }

            if ($order->isPaymentClosed()) {
                Log::warning('Payment received for a cancelled order', ['order' => $order->paymentKey(), 'gateway' => $gateway]);

                $this->recordTransaction($order, $gateway ?? $order->payment_method, 'payment_after_cancel', PaymentTransaction::STATUS_CAPTURED, $reference, $details);

                // The money was taken: record it so the custom order is refunded (CustomOrderObserver)
                if ($order instanceof CustomOrder) {
                    $order->markPaid($gateway, $reference);
                    $order->save();
                }

                return false;
            }

            $order->markPaid($gateway, $reference);
            $order->save();

            $this->recordTransaction($order, $gateway ?? $order->payment_method, 'captured', PaymentTransaction::STATUS_CAPTURED, $reference, $details);

            // One payment for several stores: its orders only exist from now on
            // (cart cleared, stock taken), and every one of them is paid by it
            if ($order instanceof CheckoutGroup) {
                $this->checkout->createOrders($order);

                return $this->markGroupOrders($order, fn (Order $child) => $child->markPaid($gateway, $reference));
            }

            return [$order];
        });

        if ($justPaid === false) {
            return false;
        }

        // Runs only once, after the payment row is committed. A gift order gets its
        // own flow (recipient WhatsApp + gift notification to the store) instead
        // of the regular "new order" notification. Each store of a multi-store
        // checkout is told about its own order only.
        foreach ($justPaid ?? [] as $paid) {
            if ($paid instanceof Order && ! $this->gifts->handlePaid($paid)) {
                $this->notifications->notifyStoreNewOrder($paid);
            }
        }

        return true;
    }

    /**
     * Record a failed / rejected / expired attempt. The payable stays payable so
     * the customer can retry with another method.
     *
     * @param  array<string, mixed>  $details
     */
    public function failOrderPayment(Payable $order, array $details = [], ?string $gateway = null, ?string $reference = null, string $event = 'failed'): void
    {
        DB::transaction(function () use ($order, $details, $gateway, $reference, $event) {
            /** @var Payable&\Illuminate\Database\Eloquent\Model $order */
            $order = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->isPaid()) {
                return;
            }

            $order->markPaymentFailed();
            $order->save();

            if ($order instanceof Order) {
                $this->gifts->syncPaymentStatus($order);
            }

            if ($order instanceof CheckoutGroup) {
                $this->markGroupOrders($order, fn (Order $child) => $child->markPaymentFailed());
            }

            $this->recordTransaction(
                $order,
                $gateway ?? $order->payment_method,
                $event,
                $event === 'cancelled' ? PaymentTransaction::STATUS_CANCELLED : PaymentTransaction::STATUS_FAILED,
                $reference,
                $details,
            );
        });
    }

    /**
     * Cancel an unpaid order and give its stock back.
     *
     * An order split from a multi-store checkout cannot be dropped on its own
     * before payment (the single payment covers all of them), so the whole
     * checkout is cancelled: every sibling order too.
     *
     * @throws CheckoutException
     */
    public function cancelOrder(Order $order, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            // Group first, then its orders: the same lock order as a payment completing
            $groupId = Order::query()->whereKey($order->getKey())->value('checkout_group_id');
            $group = $groupId ? CheckoutGroup::query()->lockForUpdate()->findOrFail($groupId) : null;

            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! $order->isCancellable()) {
                throw CheckoutException::orderNotCancellable();
            }

            $orders = $group
                ? Order::query()->where('checkout_group_id', $group->id)->orderBy('id')->lockForUpdate()->get()
                : collect([$order]);

            foreach ($orders as $each) {
                if ($each->isCancelled()) {
                    continue;
                }

                $each->forceFill([
                    'status' => Order::STATUS_CANCELLED,
                    'payment_status' => Order::PAYMENT_CANCELLED,
                    'cancellation_reason' => $reason,
                    'cancelled_at' => now(),
                ])->save();

                $this->gifts->syncPaymentStatus($each);
                $this->checkout->releaseStock($each);
            }

            $group?->forceFill([
                'status' => CheckoutGroup::STATUS_CANCELLED,
                'payment_status' => Order::PAYMENT_CANCELLED,
            ])->save();

            // A checkout used the code once, whatever number of orders carry it
            if ($order->promo_code_id) {
                $order->promoCode()->where('used_count', '>', 0)->decrement('used_count');
            }

            return $orders->firstWhere('id', $order->id);
        });
    }

    /**
     * Refund a cancelled custom order's payment in full through the gateway it
     * was paid with. `paid` (or a previous `refund_failed`) -> `refund_pending`
     * -> `refunded` | `refund_failed`; every step is logged in
     * payment_transactions. The gateway call happens outside any DB
     * transaction; concurrent calls refund once (the row is locked and moved
     * to `refund_pending` first). Returns whether the gateway accepted it.
     */
    public function refundCustomOrderPayment(CustomOrder $order, string $reason): bool
    {
        $order = DB::transaction(function () use ($order, $reason) {
            /** @var CustomOrder $order */
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! in_array($order->payment_status, [CustomOrder::PAYMENT_PAID, CustomOrder::PAYMENT_REFUND_FAILED], true)) {
                return null;   // not paid, or already refunded / being refunded
            }

            $order->forceFill(['payment_status' => CustomOrder::PAYMENT_REFUND_PENDING])->save();
            $this->recordTransaction($order, $order->payment_method, 'refund_requested', PaymentTransaction::STATUS_INITIATED, null, ['reason' => $reason]);

            return $order;
        });

        if (! $order) {
            return false;
        }

        $reference = 'REF-'.$order->paymentKey();

        try {
            $result = match ($order->payment_method) {
                Order::METHOD_TABBY   => app(TabbyService::class)->refund($order, $reference, $reason),
                Order::METHOD_TAMARA  => app(TamaraService::class)->refund($order, $reference, $reason),
                Order::METHOD_ALRAJHI => app(AlRajhiService::class)->refund($order),
                default               => ['success' => false, 'reference' => null, 'payload' => [], 'message' => 'unknown gateway'],
            };
        } catch (\Throwable $e) {
            Log::error('Refund request threw', ['order' => $order->paymentKey(), 'gateway' => $order->payment_method, 'message' => $e->getMessage()]);
            $result = ['success' => false, 'reference' => null, 'payload' => [], 'message' => $e->getMessage()];
        }

        DB::transaction(function () use ($order, $result) {
            /** @var CustomOrder $order */
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            $order->forceFill([
                'payment_status' => $result['success'] ? CustomOrder::PAYMENT_REFUNDED : CustomOrder::PAYMENT_REFUND_FAILED,
            ])->mergePaymentData(array_filter([
                'refund_reference' => $result['reference'] ?? null,
                ($result['success'] ? 'refunded_at' : 'refund_failed_at') => now()->toIso8601String(),
            ]))->save();

            $this->recordTransaction(
                $order,
                $order->payment_method,
                $result['success'] ? 'refund' : 'refund_failed',
                $result['success'] ? PaymentTransaction::STATUS_REFUNDED : PaymentTransaction::STATUS_FAILED,
                $result['reference'] ?? null,
                array_filter(['message' => $result['message'] ?? null, 'response' => $result['payload'] ?? null]),
            );
        });

        if (! $result['success']) {
            Log::error('Custom order refund failed', ['order' => $order->paymentKey(), 'gateway' => $order->payment_method, 'message' => $result['message'] ?? null]);
        }

        return $result['success'];
    }

    /**
     * Apply a payment state change to every order of a checkout (inside the
     * caller's transaction, rows locked). Cancelled orders are left alone.
     *
     * @param  callable(Order): void  $fill
     * @return list<Order> The orders changed
     */
    protected function markGroupOrders(CheckoutGroup $group, callable $fill): array
    {
        $orders = Order::query()->where('checkout_group_id', $group->id)->orderBy('id')->lockForUpdate()->get();
        $changed = [];

        foreach ($orders as $order) {
            if ($order->isCancelled() || $order->isPaid()) {
                continue;
            }

            $fill($order);
            $order->mergePaymentData(['checkout_reference' => $group->reference])->save();
            $changed[] = $order;
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordTransaction(Payable $order, ?string $gateway, string $event, string $status, ?string $reference = null, array $payload = [], ?float $amount = null): PaymentTransaction
    {
        return $order->transactions()->create([
            'gateway' => $gateway ?? 'unknown',
            'gateway_reference' => $reference,
            'event' => $event,
            'status' => $status,
            'amount' => $amount ?? $order->paymentAmount(),
            'currency' => $order->paymentCurrency(),
            'payload' => $payload,
        ]);
    }
}
