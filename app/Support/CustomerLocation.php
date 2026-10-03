<?php

namespace App\Support;

/**
 * Where the customer is, and how we know. The `source` tells the client whether
 * the distances it renders are exact (device GPS / saved address) or approximate
 * (a city centroid chosen by the user or applied as a fallback).
 */
final class CustomerLocation
{
    public const SOURCE_GPS     = 'gps';      // latitude / longitude sent by the device
    public const SOURCE_LAST_KNOWN = 'last_known'; // last GPS position of the signed-in customer (cached)
    public const SOURCE_ADDRESS = 'address';  // default saved address of the customer
    public const SOURCE_PROFILE = 'profile';  // saved position of a captain / shopper account
    public const SOURCE_CITY    = 'city';     // city centroid (manual selection or default)

    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $source,
        public readonly ?City $city = null,
    ) {
    }

    public static function gps(float $latitude, float $longitude): self
    {
        return new self($latitude, $longitude, self::SOURCE_GPS);
    }

    public static function fromCity(City $city): self
    {
        return new self($city->latitude, $city->longitude, self::SOURCE_CITY, $city);
    }

    public function isApproximate(): bool
    {
        return $this->source === self::SOURCE_CITY;
    }

    /**
     * Nearby radius to use when the client does not send one.
     */
    public function defaultRadiusKm(): float
    {
        return $this->city?->radiusKm ?? (float) config('stores.home.nearby_radius_km', 25);
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function coordinates(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
