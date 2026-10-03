<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * A serviced city from config/cities.php. Used as the customer's position when
 * no GPS coordinates are available (guest fallback / manual selection).
 */
final class City
{
    /**
     * @param  array{ar: string, en: string}  $names
     */
    public function __construct(
        public readonly string $key,
        public readonly array $names,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly float $radiusKm,
    ) {
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->names[$locale] ?? $this->names['en'] ?? $this->key;
    }

    public static function find(?string $key): ?self
    {
        if ($key === null || $key === '') {
            return null;
        }

        $key = strtolower(trim($key));
        $row = config("cities.list.{$key}");

        return $row ? self::fromConfig($key, $row) : null;
    }

    public static function default(): ?self
    {
        return self::find(config('cities.default'));
    }

    /**
     * @return Collection<int, self>
     */
    public static function all(): Collection
    {
        return collect(config('cities.list', []))
            ->map(fn (array $row, string $key) => self::fromConfig($key, $row))
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(config('cities.list', []));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function fromConfig(string $key, array $row): self
    {
        return new self(
            key: $key,
            names: $row['name'] ?? ['en' => ucfirst($key)],
            latitude: (float) $row['latitude'],
            longitude: (float) $row['longitude'],
            radiusKm: (float) ($row['radius_km'] ?? config('stores.home.nearby_radius_km', 25)),
        );
    }
}
