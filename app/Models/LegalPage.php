<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * A legal center page (privacy policy, terms and conditions).
 *
 * `title` and `content` are JSON columns holding one value per locale. Reading
 * them (`$page->title`, `$page->content`) returns the current application
 * locale's value, which SetLocale resolves from the Accept-Language header.
 * `content` is the page's ordered sections: list<array{key: string, heading: string, body: string}>.
 * A section's `key` is the same in every locale (the app maps it to an icon).
 *
 * @property int $id
 * @property string $type
 * @property string $title Translated for the current locale
 * @property array $content Translated for the current locale
 * @property bool $is_published
 * @property Carbon|null $updated_at
 */
class LegalPage extends Model
{
    use HasTranslations;

    public const TYPE_PRIVACY_POLICY = 'privacy_policy';

    public const TYPE_TERMS_AND_CONDITIONS = 'terms_and_conditions';

    public const TYPES = [self::TYPE_PRIVACY_POLICY, self::TYPE_TERMS_AND_CONDITIONS];

    public const LOCALES = ['ar', 'en'];

    /**
     * @var array<int, string>
     */
    protected $fillable = ['type', 'title', 'content', 'is_published'];

    /**
     * The translatable JSON columns are encoded/decoded by HasTranslations.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_published' => 'boolean',
    ];

    /**
     * @var array<int, string>
     */
    public array $translatable = ['title', 'content'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * The sections of one locale (falling back to the app's fallback locale).
     *
     * @return list<array{key: string, heading: string, body: string}>
     */
    public function sections(?string $locale = null): array
    {
        // MySQL's JSON type reorders object keys: give them back in reading order
        return array_map(fn (array $section) => [
            'key' => $section['key'] ?? null,
            'heading' => $section['heading'] ?? null,
            'body' => $section['body'] ?? null,
        ], array_values((array) $this->getTranslation('content', $locale ?? app()->getLocale())));
    }
}
