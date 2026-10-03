<?php

namespace App\Services\Firebase;

use App\Models\Order;
use App\Support\Geo;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Database;
use Throwable;

/**
 * Live feed of orders waiting for a captain, kept in Firebase Realtime
 * Database under `available_orders/{order_id}`. The captain app listens on
 * that node, so an order shows up on every available captain's screen the
 * moment the store sends it and disappears once it leaves the pool.
 *
 * MySQL stays the source of truth: a Firebase failure is logged and never
 * rolls back or blocks the status change. Only the backend writes here
 * (admin credentials); captains get read-only access through the database rules.
 */
class CaptainOrderFeed
{
    public function __construct(protected Container $app) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.captain_feed.enabled', true)
            && (string) config('firebase.projects.'.config('firebase.default').'.database.url') !== '';
    }

    /**
     * Put (or overwrite) the order in the available pool.
     */
    public function publish(Order $order): bool
    {
        return $this->write('publish', $order, fn (Database $db) => $db->getReference($this->path($order))->set($this->payload($order)));
    }

    /**
     * Take the order out of the pool (claimed by a captain, delivered or cancelled).
     */
    public function remove(Order $order): bool
    {
        return $this->write('remove', $order, fn (Database $db) => $db->getReference($this->path($order))->remove());
    }

    public function path(Order $order): string
    {
        return trim((string) config('services.captain_feed.path', 'available_orders'), '/').'/'.$order->getKey();
    }

    /**
     * What a captain needs to decide on the order before accepting it. The
     * customer's name and phone are left out: they are shared only with the
     * captain who takes the order.
     *
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $order->loadMissing('storeProfile.mainBranch')->loadCount('items');

        $store = $order->storeProfile;
        $branch = $store?->mainBranch;

        $pickup = $this->point($branch?->latitude, $branch?->longitude);
        $dropoff = $this->point($order->shipping_latitude, $order->shipping_longitude);

        $distance = $pickup && $dropoff
            ? round(Geo::distanceKm($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']), 2)
            : null;

        return [
            'order_id' => $order->getKey(),
            'order_number' => $order->order_number,
            'status' => 'available',
            'currency' => $order->currency,
            // The captain is paid the delivery charge (delivery + express fee)
            'earnings' => $order->shipping_cost,
            'order_total' => round((float) $order->total_amount, 2),
            'items_count' => (int) $order->items_count,
            'distance_km' => $distance,
            'delivery_type' => $order->delivery_type,
            'delivery_window' => [
                'label' => $order->delivery_slot_label,
                'start' => $order->delivery_window_start?->toIso8601String(),
                'end' => $order->delivery_window_end?->toIso8601String(),
            ],
            'pickup' => [
                'store_id' => $order->store_id,
                'store_name' => $store?->store_name,
                'address' => $branch?->address,
                'city' => $branch?->city,
                'phone' => $branch?->phone ?: $store?->phone,
                'lat' => $pickup['lat'] ?? null,
                'lng' => $pickup['lng'] ?? null,
            ],
            'dropoff' => [
                'address' => $order->shipping_address,
                'district' => $order->shipping_district,
                'city' => $order->shipping_city,
                'lat' => $dropoff['lat'] ?? null,
                'lng' => $dropoff['lng'] ?? null,
            ],
            'dispatched_at' => $order->dispatched_at?->toIso8601String(),
            // Firebase server time (ms) so clients can sort the feed newest-first
            'published_at' => ['.sv' => 'timestamp'],
        ];
    }

    /**
     * @param  callable(Database): mixed  $operation
     */
    protected function write(string $action, Order $order, callable $operation): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $operation($this->app->make(Database::class));

            return true;
        } catch (Throwable $e) {
            Log::warning("Captain order feed: {$action} failed", [
                'order_id' => $order->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function point(mixed $latitude, mixed $longitude): ?array
    {
        if (! Geo::isValidLatitude($latitude) || ! Geo::isValidLongitude($longitude)) {
            return null;
        }

        return ['lat' => (float) $latitude, 'lng' => (float) $longitude];
    }
}
