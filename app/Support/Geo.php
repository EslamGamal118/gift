<?php

namespace App\Support;

/**
 * Great-circle distance helpers (Haversine / spherical law of cosines).
 */
class Geo
{
    public const EARTH_RADIUS_KM = 6371;

    /**
     * SQL expression (MySQL 5.7+) returning the straight-line distance in km
     * between the given point and the `latitude` / `longitude` columns of
     * `$table`. NULL when the row has no coordinates.
     *
     * Bindings are returned separately so the caller can pass them to the query builder.
     *
     * @return array{0: string, 1: array<int, float>}
     */
    public static function distanceSql(float $latitude, float $longitude, string $table): array
    {
        // POINT(x, y) is (longitude, latitude) for ST_Distance_Sphere
        $sql = sprintf(
            'ST_Distance_Sphere(POINT(?, ?), POINT(%s.longitude, %s.latitude), %d) / 1000',
            $table,
            $table,
            self::EARTH_RADIUS_KM * 1000,
        );

        return [$sql, [$longitude, $latitude]];
    }

    /**
     * Multiplier turning a straight-line distance into an approximate driving distance.
     */
    public static function roadFactor(): float
    {
        return max(1.0, (float) config('stores.delivery.road_factor', 1));
    }

    /**
     * Approximate driving distance for a straight-line distance, in km.
     */
    public static function roadDistanceKm(float $straightKm): float
    {
        return round($straightKm * self::roadFactor(), 2);
    }

    /**
     * Distance in km between two coordinates.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Rectangle enclosing a circle of `$km` around a point. Cheap `BETWEEN`
     * filter run before the exact Haversine so the (lat, lng) index is used.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public static function boundingBox(float $latitude, float $longitude, float $km): array
    {
        $latDelta = rad2deg($km / self::EARTH_RADIUS_KM);
        $cosLat   = cos(deg2rad($latitude));
        $lngDelta = $cosLat > 0.0001 ? rad2deg($km / self::EARTH_RADIUS_KM / $cosLat) : 180.0;

        return [
            'min_lat' => max(-90.0, $latitude - $latDelta),
            'max_lat' => min(90.0, $latitude + $latDelta),
            'min_lng' => max(-180.0, $longitude - $lngDelta),
            'max_lng' => min(180.0, $longitude + $lngDelta),
        ];
    }

    /**
     * Human label for a distance, e.g. "2.4 km" / "850 m" (localized unit).
     */
    public static function formatKm(?float $km): ?string
    {
        if ($km === null) {
            return null;
        }

        if ($km < 1) {
            return __('home.distance_m', ['m' => (int) round($km * 1000, -1)]);
        }

        return __('home.distance_km', ['km' => number_format($km, 1)]);
    }

    public static function isValidLatitude(mixed $value): bool
    {
        return is_numeric($value) && $value >= -90 && $value <= 90;
    }

    public static function isValidLongitude(mixed $value): bool
    {
        return is_numeric($value) && $value >= -180 && $value <= 180;
    }
}
