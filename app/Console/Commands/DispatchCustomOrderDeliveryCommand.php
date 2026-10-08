<?php

namespace App\Console\Commands;

use App\Models\CustomOrder;
use App\Services\CustomOrderDeliveryService;
use App\Services\Delivery\AlshrouqException;
use Illuminate\Console\Command;

/**
 * Send one paid custom order to Alshrouq by hand (support), e.g. when the
 * shopper's "send to driver" failed and cannot be retried from the app.
 */
class DispatchCustomOrderDeliveryCommand extends Command
{
    protected $signature = 'custom-orders:dispatch-delivery {id : Custom order id}';

    protected $description = 'Create a paid custom order at Alshrouq Delivery';

    public function handle(CustomOrderDeliveryService $delivery): int
    {
        $orders = CustomOrder::query()
            ->whereKey($this->argument('id'))
            ->where('status', CustomOrder::STATUS_PAID)
            ->whereNull('delivery_reference')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('This custom order is not paid, or is already with Alshrouq.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($orders as $order) {
            try {
                $order = $delivery->dispatch($order);
                $this->info("{$order->order_number}: Alshrouq order {$order->delivery_reference} ({$order->status})");
            } catch (AlshrouqException $e) {
                $failed++;
                $this->error("{$order->order_number}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
