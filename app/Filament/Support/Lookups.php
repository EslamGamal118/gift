<?php

namespace App\Filament\Support;

use App\Models\Category;
use App\Models\StoreProfile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Option lists and file links shared by the admin resources.
 */
class Lookups
{
    /**
     * Serviced cities (config/cities.php), labelled in the current language.
     *
     * @return array<string, string>
     */
    public static function cities(): array
    {
        return collect(config('cities.list'))
            ->mapWithKeys(fn (array $city, string $key) => [$key => $city['name'][app()->getLocale()] ?? $city['name']['ar'] ?? $key])
            ->all();
    }

    public static function cityName(?string $key): ?string
    {
        return $key ? (self::cities()[$key] ?? $key) : null;
    }

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return Category::query()->orderBy('id')->get()->mapWithKeys(fn (Category $c) => [$c->id => $c->name])->all();
    }

    /**
     * Stores for pickers, keyed by the owner account id (products.store_id /
     * addons.store_id point at users.id, not at store_profiles.id).
     *
     * @return array<int, string>
     */
    public static function stores(): array
    {
        return StoreProfile::query()->orderBy('store_name')->pluck('store_name', 'user_id')->all();
    }

    /**
     * Public URL of an uploaded file (stored path on the public disk, or already a URL).
     */
    public static function fileUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : Storage::disk('public')->url($path);
    }
}
