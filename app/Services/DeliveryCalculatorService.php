<?php

namespace App\Services;

use App\Models\StoreProfile;
use App\Support\Geo;

/**
 * Prices delivery and estimates its duration from the road distance between
 * the customer and the store's nearest branch (config/stores.php `delivery`).
 *
 * Pricing is tiered: `base_fee` covers the first `base_distance_km`, then
 * `fee_per_extra_km` is added for every started km beyond it, capped at
 * `max_fee`. A store's flat `delivery_fee` overrides the whole calculation.
 * While delivery is free (config `checkout.free_delivery`) every fee is 0.
 *
 * Distances passed in are already road distances (`distance_km` from
 * StoreProfile::withDistanceTo(), or roadDistanceKm() for PHP-side values).
 */
class DeliveryCalculatorService
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('stores.delivery', []);
    }

    /**
     * Fee, time window and availability for a store at the given road distance.
     *
     * `$preparationMinutes` overrides the store's default preparation time,
     * e.g. with the preparation time of the specific product being viewed.
     *
     * @return array{distance_km: float|null, fee: float, currency: string, time: array{min: int, max: int}, available: bool, is_estimated: bool}
     */
    public function quote(StoreProfile $store, ?float $distanceKm, ?int $preparationMinutes = null): array
    {
        return [
            'distance_km'  => $distanceKm,
            'fee'          => $this->fee($store, $distanceKm),
            'currency'     => $this->currency(),
            'time'         => $this->time($store, $distanceKm, $preparationMinutes),
            'available'    => $this->deliversTo($distanceKm),
            'is_estimated' => $distanceKm !== null,
        ];
    }

    /**
     * Delivery fee in the configured currency. Without a distance only the base fee applies.
     */
    public function fee(StoreProfile $store, ?float $distanceKm): float
    {
        if (self::isFree()) {
            return 0.0;
        }

        if ($store->delivery_fee !== null) {
            return round((float) $store->delivery_fee, 2);
        }

        return $this->distanceFee($distanceKm);
    }

    /**
     * Whether the customer is charged nothing for delivery (config `checkout.free_delivery`).
     */
    public static function isFree(): bool
    {
        return (bool) config('checkout.free_delivery', false);
    }

    /**
     * The tiered distance price, ignoring any store override.
     */
    public function distanceFee(?float $distanceKm): float
    {
        $pricing = $this->config['pricing'] ?? [];

        $fee = (float) ($pricing['base_fee'] ?? 0);

        if ($distanceKm !== null) {
            $extraKm = round($distanceKm - (float) ($pricing['base_distance_km'] ?? 0), 2);

            if ($extraKm > 0) {
                $fee += ceil($extraKm) * (float) ($pricing['fee_per_extra_km'] ?? 0);
            }
        }

        if (isset($pricing['max_fee'])) {
            $fee = min($fee, (float) $pricing['max_fee']);
        }

        return round($fee, 2);
    }

    /**
     * Estimated delivery window in minutes: preparation + travel, plus a buffer.
     *
     * @return array{min: int, max: int}
     */
    public function time(StoreProfile $store, ?float $distanceKm, ?int $preparationMinutes = null): array
    {
        $preparation = (int) ($preparationMinutes ?? $store->preparation_time ?? $this->config['default_preparation_minutes'] ?? 20);
        $buffer      = (int) ($this->config['buffer_minutes'] ?? 15);
        $speed       = (float) ($this->config['average_speed_kmh'] ?? 30);

        $travel = ($distanceKm !== null && $speed > 0)
            ? (int) ceil($distanceKm / $speed * 60)
            : 0;

        $min = $preparation + $travel;

        return ['min' => $min, 'max' => $min + $buffer];
    }

    /**
     * Whether the store delivers to a customer at the given road distance.
     */
    public function deliversTo(?float $distanceKm): bool
    {
        $max = $this->config['max_distance_km'] ?? null;

        return $distanceKm === null || $max === null || $distanceKm <= $max;
    }

    /**
     * Road distance for a straight-line distance computed in PHP.
     */
    public function roadDistanceKm(float $straightKm): float
    {
        return Geo::roadDistanceKm($straightKm);
    }

    public function currency(): string
    {
        return $this->config['currency'] ?? 'SAR';
    }
}
