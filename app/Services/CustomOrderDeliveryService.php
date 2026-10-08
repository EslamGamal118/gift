<?php

namespace App\Services;

use App\Models\CustomOrder;
use App\Services\Delivery\AlshrouqClient;
use App\Services\Delivery\AlshrouqException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivery of paid custom orders by the delivery company (Alshrouq):
 *
 *   dispatch()  the shopper sends the paid order to the driver
 *               (ShopperOrderService::sendToDriver()): create the Alshrouq order (pickup = the shopper's
 *               pickup address, drop-off = the customer's delivery address)
 *   apply()     every status update (create response, webhook) becomes the
 *               order's `status` until delivered (`completed`) or cancelled,
 *               see CustomOrder::canMoveDeliveryTo(); the customer and the
 *               shopper are told about each change
 */
class CustomOrderDeliveryService
{
    public function __construct(
        protected AlshrouqClient $alshrouq,
        protected NotificationService $notifications,
    ) {}

    /**
     * Our status for one of Alshrouq's (its status id or its name), or null.
     */
    public static function mapStatus(int|string|null $providerStatus): ?string
    {
        return AlshrouqClient::mapStatus($providerStatus);
    }

    /*
    |--------------------------------------------------------------------------
    | Dispatch
    |--------------------------------------------------------------------------
    */

    /**
     * Create the order at Alshrouq. Once only: an order that already has its
     * Alshrouq reference is left alone. On failure the error is kept on the
     * order (`delivery_error`) and rethrown.
     *
     * @throws AlshrouqException
     */
    public function dispatch(CustomOrder $order): CustomOrder
    {
        try {
            [$order, $created] = $this->createAtAlshrouq($order);
        } catch (AlshrouqException $e) {
            $order->forceFill(['delivery_error' => mb_substr($e->getMessage(), 0, 255)])->save();
            Log::warning('Alshrouq dispatch failed', ['custom_order_id' => $order->id, 'error' => $e->getMessage()]);

            throw $e;
        }

        // Usually "Order created" (status_id 1)
        if ($created && ($status = self::mapStatus($created['status_id'] ?? null) ?? self::mapStatus($created['status_label'] ?? null))) {
            $this->apply($order, $status, (string) ($created['status_label'] ?? $created['status_id']));
        }

        return $order->refresh();
    }

    /**
     * Under the row lock, so concurrent dispatches never create two Alshrouq orders.
     *
     * @return array{0: CustomOrder, 1: array<string, mixed>|null}
     */
    protected function createAtAlshrouq(CustomOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            /** @var CustomOrder $order */
            $order = CustomOrder::query()->with(['pickupAddress', 'user'])->lockForUpdate()->findOrFail($order->getKey());

            if ($order->delivery_reference || $order->status !== CustomOrder::STATUS_PAID) {
                return [$order, null];
            }

            $created = $this->alshrouq->createOrder($this->payload($order));

            $order->forceFill([
                'delivery_reference' => (string) $created['order_id'],
                'delivery_dispatched_at' => now(),
                'delivery_data' => $created,
                'delivery_error' => null,
            ])->save();

            return [$order, $created];
        });
    }

    /**
     * The create-order request: no Alshrouq branch, the pickup point is given
     * by its coordinates.
     *
     * @return array<string, mixed>
     *
     * @throws AlshrouqException  without pickup or drop-off coordinates
     */
    public function payload(CustomOrder $order): array
    {
        $pickup = $order->pickupAddress;

        if (! $pickup?->latitude || ! $pickup?->longitude) {
            throw new AlshrouqException("Custom order {$order->order_number} has no pickup coordinates.");
        }

        if (! $order->delivery_latitude || ! $order->delivery_longitude) {
            throw new AlshrouqException("Custom order {$order->order_number} has no delivery coordinates.");
        }

        return [
            'branch_lat' => (float) $pickup->latitude,
            'branch_lng' => (float) $pickup->longitude,
            'branch_id' => null,
            'client_order_id' => (string) $order->id,
            'value' => round((float) $order->total_amount, 2),
            'payment_type' => (int) config('alshrouq.payment_type', 3),
            'preparation_time' => max(0, (int) config('alshrouq.preparation_time', 0)),
            'customer_lat' => (float) $order->delivery_latitude,
            'customer_lng' => (float) $order->delivery_longitude,
            'customer_address' => (string) $order->delivery_address,
            'customer_phone' => self::localPhone((string) ($order->delivery_phone ?: $order->user?->phone)),
            'customer_name' => (string) ($order->delivery_name ?: $order->user?->name),
            'details' => trim($order->order_number.($order->notes ? ' - '.$order->notes : '')),
        ];
    }

    /**
     * KSA mobile as Alshrouq expects it: 5XXXXXXXX (no 966 / 0 prefix).
     */
    public static function localPhone(string $phone): string
    {
        return AlshrouqClient::localPhone($phone);
    }

    /*
    |--------------------------------------------------------------------------
    | Status updates
    |--------------------------------------------------------------------------
    */

    /**
     * Move the order to `$status`. Returns false (status unchanged) when the
     * update does not apply: not paid yet, already finished, a repeated or
     * late update. The raw provider status and the driver details are kept
     * either way.
     *
     * @param  array<string, mixed>|null  $driver  Alshrouq's `driver` object
     */
    public function apply(CustomOrder $order, string $status, string $providerStatus, ?string $deliveryId = null, ?string $reason = null, ?array $driver = null): bool
    {
        [$moved, $previous, $order] = DB::transaction(function () use ($order, $status, $providerStatus, $deliveryId, $reason, $driver) {
            /** @var CustomOrder $order */
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $previous = $order->status;
            $moved = $order->canMoveDeliveryTo($status);

            $order->forceFill(array_filter([
                'delivery_reference' => $deliveryId ?: $order->delivery_reference,
                'delivery_status' => mb_substr($providerStatus, 0, 64),
                'delivery_updated_at' => now(),
                'delivery_driver' => $driver ? self::driver($driver) : null,
            ]));

            if ($moved) {
                $order->forceFill(['status' => $status] + match ($status) {
                    CustomOrder::STATUS_COMPLETED => ['completed_at' => now()],
                    CustomOrder::STATUS_CANCELLED => [
                        'cancelled_at' => now(),
                        'cancelled_by' => CustomOrder::ACTOR_DELIVERY,
                        'cancellation_reason' => $reason ?: $order->cancellation_reason,
                    ],
                    default => [],
                });
            }

            $order->save();

            return [$moved, $previous, $order];
        });

        if (! $moved) {
            Log::info('Delivery update ignored', ['custom_order_id' => $order->id, 'status' => $previous, 'update' => $status]);

            return false;
        }

        $keys = $status === CustomOrder::STATUS_CANCELLED
            ? ['notifications.custom_order_delivery_cancelled_title', 'notifications.custom_order_delivery_cancelled_body']
            : null;

        try {
            $this->notifications->notifyCustomOrderStatusChanged($order, $previous, $keys);
        } catch (\Throwable $e) {
            // Alshrouq must not retry an update we already saved
            Log::error('Delivery status notification failed', ['custom_order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * The driver details we keep (and show in the apps).
     *
     * @param  array<string, mixed>  $driver
     * @return array<string, mixed>
     */
    protected static function driver(array $driver): array
    {
        return array_filter([
            'id' => $driver['id'] ?? null,
            'name' => $driver['name'] ?? null,
            'phone' => $driver['phone'] ?? null,
            'status' => $driver['status'] ?? null,
            'tracking_url' => $driver['tracking_url'] ?? null,
            'location' => isset($driver['location']['lat'], $driver['location']['lng'])
                ? ['lat' => (float) $driver['location']['lat'], 'lng' => (float) $driver['location']['lng']]
                : null,
            'updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
