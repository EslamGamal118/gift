<?php

namespace App\Models\Concerns;

use App\Services\SearchVocabulary;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keyword search over the model's normalized `search_text` generated column.
 *
 * The keyword is normalized like the column (Arabic letter variants, case),
 * split into words, and every word must match, in any order. A word matches
 * itself as a substring or, from 4 letters on, a known name word within its
 * typo distance (see SearchVocabulary). Models refresh the vocabulary when a
 * name in it changes, via the `searchVocabularyColumn` property.
 */
trait SmartSearchable
{
    public static function bootSmartSearchable(): void
    {
        static::saved(function (self $model) {
            if ($model->wasRecentlyCreated || $model->wasChanged($model->searchVocabularyColumn)) {
                SearchVocabulary::forget();
            }
        });
        static::deleted(fn () => SearchVocabulary::forget());
    }

    public function scopeSmartSearch(Builder $query, string $term): Builder
    {
        $tokens = SearchText::tokens($term);

        if ($tokens === []) {
            return $query;
        }

        $column     = $this->qualifyColumn('search_text');
        $vocabulary = app(SearchVocabulary::class);

        foreach ($tokens as $token) {
            $query->where(function (Builder $q) use ($column, $vocabulary, $token) {
                foreach ($vocabulary->expand($token) as $word) {
                    $q->orWhere($column, 'like', "%{$word}%");
                }
            });
        }

        return $query;
    }
}
