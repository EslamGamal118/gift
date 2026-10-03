<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * @property int    $id
 * @property string $name       الاسم بلغة التطبيق الحالية (يُقرأ من عمود JSON)
 * @property string|null $image
 * @property bool   $is_active
 */
class Category extends Model
{
    use HasFactory, HasTranslations;

    /**
     * اللغات المدعومة للحقول القابلة للترجمة.
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
        'name',
        'image',
        'is_active',
        'is_special',
    ];

    /**
     * The attributes that should be cast.
     *
     * لا يتم تحويل `name` هنا لأن HasTranslations يتولّى ترميز/فك ترميز JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'  => 'boolean',
        'is_special' => 'boolean',
    ];

    /**
     * الحقول القابلة للترجمة (تُخزَّن كـ JSON: {"ar": "...", "en": "..."})
     *
     * @var array<int, string>
     */
    public array $translatable = ['name'];

    /**
     * المتسوقون العاملون ضمن هذا التصنيف
     */
    public function shopperProfiles(): BelongsToMany
    {
        return $this->belongsToMany(ShopperProfile::class, 'category_shopper_profile')
            ->withTimestamps();
    }
    /**
     * Special categories: digital / online-only gifts shown in their own home section.
     */
    public function scopeSpecial(Builder $query): Builder
    {
        return $query->where('is_special', true);
    }

    /**
     * Regular (non-special) categories shown in the main horizontal list.
     */
    public function scopeRegular(Builder $query): Builder
    {
        return $query->where('is_special', false);
    }

    /**
     * Add-ons available for products of this category.
     */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'category_addon')
            ->withTimestamps();
    }

    /**
     * Stores registered under this category.
     */
    public function stores(): HasMany
    {
        return $this->hasMany(StoreProfile::class);
    }

    /**
     * Keyword search across every locale of the name.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            foreach (self::LOCALES as $locale) {
                $q->orWhere("name->{$locale}", 'like', "%{$term}%");
            }
        });
    }

    /**
     * Products listed under this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * التصنيفات المفعّلة فقط
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

  
    public function scopeSearchName(Builder $query, string $term, ?string $locale = null): Builder
    {
        $locale = $locale ?? app()->getLocale();

        return $query->where("name->{$locale}", 'like', "%{$term}%");
    }
}
