<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * @property int    $id
 * @property string $name      الاسم بلغة التطبيق الحالية (يُقرأ من عمود JSON)
 * @property bool   $is_active
 */
class VehicleType extends Model
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
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * لا يتم تحويل `name` هنا لأن HasTranslations يتولّى ترميز/فك ترميز JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * الحقول القابلة للترجمة (تُخزَّن كـ JSON: {"ar": "...", "en": "..."})
     *
     * @var array<int, string>
     */
    public array $translatable = ['name'];

    /**
     * الكباتن الذين يستخدمون هذا النوع من المركبات
     */
    public function captainProfiles(): HasMany
    {
        return $this->hasMany(CaptainProfile::class);
    }

    /**
     * الأنواع المفعّلة فقط
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * البحث في الاسم داخل عمود JSON بلغة محددة (أو اللغة الحالية افتراضيًا).
     *
     * مثال: VehicleType::searchName('Motorcycle', 'en')->get()
     */
    public function scopeSearchName(Builder $query, string $term, ?string $locale = null): Builder
    {
        $locale = $locale ?? app()->getLocale();

        return $query->where("name->{$locale}", 'like', "%{$term}%");
    }
}
