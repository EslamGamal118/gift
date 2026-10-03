<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Home screen banner / slider item.
 *
 * `title`, `subtitle` and `button_text` are JSON columns holding one value per
 * locale ({"ar": "...", "en": "..."}). Reading the attribute (`$banner->title`)
 * returns the value for the current application locale, which SetLocale resolves
 * from the request's Accept-Language header.
 *
 * @property int         $id
 * @property string      $title        Translated for the current locale
 * @property string|null $subtitle     Translated for the current locale
 * @property string|null $button_text  Translated for the current locale
 * @property string      $image
 * @property string|null $link
 * @property int         $sort_order
 * @property bool        $is_active
 */
class Banner extends Model
{
    use HasFactory, HasTranslations;

    /**
     * Supported locales for translatable fields.
     *
     * @var array<int, string>
     */
    public const LOCALES = ['ar', 'en'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'subtitle',
        'button_text',
        'image',
        'link',
        'sort_order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * The translatable JSON columns are encoded/decoded by HasTranslations
     * (equivalent to an `array` cast plus per-locale resolution), so they are
     * intentionally not listed here.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    /**
     * Translatable attributes stored as JSON: {"ar": "...", "en": "..."}
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'subtitle', 'button_text'];

    /**
     * Banners that should be displayed in the app.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Display order: lowest `sort_order` first, then oldest first.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
