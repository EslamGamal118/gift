<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Models\NotificationContent;
use App\Models\ShopperPayout;
use App\Models\ShopperProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Logs money transferred to a personal shopper. A payout settles completed,
 * paid custom orders not paid out yet; for each one the shopper gets back the
 * items they bought (final_amount - shopper_fees) plus their fee minus the
 * platform commission. Amounts are frozen in shopper_payout_items, and an
 * order can only ever be paid out once.
 */
class ShopperPayoutService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Custom orders the shopper can be paid for now.
     */
    public function dueOrders(ShopperProfile $shopper): Builder
    {
        return CustomOrder::query()
            ->where('custom_orders.shopper_id', $shopper->user_id)
            ->where('custom_orders.status', CustomOrder::STATUS_COMPLETED)
            ->where('custom_orders.payment_status', CustomOrder::PAYMENT_PAID)
            ->whereDoesntHave('payoutItem');
    }

    /**
     * @param  list<int>|null  $orderIds  null = every due order
     * @param  array{method: string, reference?: ?string, receipt_path?: ?string, notes?: ?string, transferred_at?: mixed}  $details
     *
     * @throws RuntimeException when none of the orders can be paid out
     */
    public function payout(ShopperProfile $shopper, ?array $orderIds, array $details, ?User $admin = null): ShopperPayout
    {
        $payout = DB::transaction(function () use ($shopper, $orderIds, $details, $admin): ShopperPayout {
            // Locked so two admins cannot pay the same orders twice (shopper_payout_items.custom_order_id is unique too)
            $orders = $this->dueOrders($shopper)
                ->when($orderIds !== null, fn ($q) => $q->whereKey($orderIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                throw new RuntimeException(__('admin.payouts.nothing_due'));
            }

            $items = $orders->map(fn (CustomOrder $order): array => ['custom_order_id' => $order->id]
                + $shopper->shareOf($order)
                + ['commission_rate' => $shopper->commission_rate]);

            $payout = $shopper->payouts()->create([
                'amount' => round($items->sum('shopper_amount'), 2),
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

        $this->notifyShopper($shopper, $payout);

        return $payout;
    }

    protected function notifyShopper(ShopperProfile $shopper, ShopperPayout $payout): void
    {
        try {
            $this->notifications->sendFromSystem(
                recipients: $shopper->user()->first(),
                titleKey: 'notifications.shopper_payout_title',
                bodyKey: 'notifications.shopper_payout_body',
                bodyParams: [
                    'amount' => number_format((float) $payout->amount, 2),
                    'currency' => $payout->currency,
                    'orders_count' => (string) $payout->orders_count,
                ],
                type: NotificationContent::TYPE_WALLET_UPDATE,
                data: ['shopper_payout_id' => (string) $payout->id],
            );
        } catch (Throwable $e) {
            Log::error('Shopper payout notification failed', ['payout_id' => $payout->id, 'error' => $e->getMessage()]);
        }
    }
}
