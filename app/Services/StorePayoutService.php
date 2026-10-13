<?php

namespace App\Services;

use App\Models\NotificationContent;
use App\Models\Order;
use App\Models\StorePayout;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Logs money transferred to a store. A payout settles a set of the store's
 * delivered, paid orders that were not paid out yet: each order's subtotal,
 * commission and store share are frozen in store_payout_items, and the payout
 * amount is their total. An order can only ever be paid out once.
 */
class StorePayoutService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Orders the store can be paid for now.
     */
    public function dueOrders(StoreProfile $store)
    {
        return Order::query()->forStore($store->user_id)->awaitingPayout();
    }

    /**
     * @param  list<int>|null  $orderIds  null = every due order
     * @param  array{method: string, reference?: ?string, receipt_path?: ?string, notes?: ?string, transferred_at?: mixed}  $details
     *
     * @throws RuntimeException when none of the orders can be paid out
     */
    public function payout(StoreProfile $store, ?array $orderIds, array $details, ?User $admin = null): StorePayout
    {
        $payout = DB::transaction(function () use ($store, $orderIds, $details, $admin): StorePayout {
            // Locked so two admins cannot pay the same orders twice (store_payout_items.order_id is unique too)
            $orders = $this->dueOrders($store)
                ->when($orderIds !== null, fn ($q) => $q->whereKey($orderIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                throw new RuntimeException(__('admin.payouts.nothing_due'));
            }

            $items = $orders->map(function (Order $order) use ($store): array {
                $commission = $store->commissionFor((float) $order->subtotal);

                return [
                    'order_id' => $order->id,
                    'subtotal' => $order->subtotal,
                    'commission_rate' => $store->commission_rate,
                    'commission_amount' => $commission,
                    'store_amount' => round((float) $order->subtotal - $commission, 2),
                ];
            });

            $payout = $store->payouts()->create([
                'amount' => round($items->sum('store_amount'), 2),
                'currency' => $orders->first()->currency ?: 'SAR',
                'orders_count' => $items->count(),
                'method' => $details['method'],
                'reference' => $details['reference'] ?? null,
                'receipt_path' => $details['receipt_path'] ?? null,
                'notes' => $details['notes'] ?? null,
                'transferred_at' => $details['transferred_at'] ?? now(),
                'created_by' => $admin?->id,
            ]);

            $payout->items()->createMany($items->all());

            return $payout;
        });

        $this->notifyStore($store, $payout);

        return $payout;
    }

    protected function notifyStore(StoreProfile $store, StorePayout $payout): void
    {
        try {
            $this->notifications->sendFromSystem(
                recipients: $store->user()->first(),
                titleKey: 'notifications.store_payout_title',
                bodyKey: 'notifications.store_payout_body',
                bodyParams: [
                    'amount' => number_format((float) $payout->amount, 2),
                    'currency' => $payout->currency,
                    'orders_count' => (string) $payout->orders_count,
                ],
                type: NotificationContent::TYPE_WALLET_UPDATE,
                data: ['payout_id' => (string) $payout->id],
            );
        } catch (Throwable $e) {
            Log::error('Store payout notification failed', ['payout_id' => $payout->id, 'error' => $e->getMessage()]);
        }
    }
}
