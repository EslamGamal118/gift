<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * One FAQ item. `question` and `answer` are JSON columns holding one value per
 * locale; reading them returns the current locale's (Accept-Language via SetLocale).
 *
 * @property int $id
 * @property string $question Translated for the current locale
 * @property string $answer Translated for the current locale
 * @property string|null $category
 * @property int $sort_order
 * @property bool $is_active
 */
class Faq extends Model
{
    use HasTranslations;

    public const CATEGORIES = ['general', 'orders', 'payments', 'delivery', 'support'];

    /**
     * @var array<int, string>
     */
    protected $fillable = ['question', 'answer', 'category', 'sort_order', 'is_active'];

    /**
     * The translatable JSON columns are encoded/decoded by HasTranslations.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @var array<int, string>
     */
    public array $translatable = ['question', 'answer'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Screen order: sort_order, then oldest first.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Question or answer containing the keyword, in the current locale.
     */
    public function scopeMatching(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        $locale = app()->getLocale();
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(question, '$.\"{$locale}\"'))) LIKE ?", [$like])
            ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(answer, '$.\"{$locale}\"'))) LIKE ?", [$like]));
    }
}
