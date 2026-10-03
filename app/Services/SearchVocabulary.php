<?php

namespace App\Services;

use App\Support\SearchText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Typo tolerance for catalog search: the distinct normalized words of product
 * and store names, cached, so a misspelled keyword word ("شوكلاته") can be
 * expanded to the real words close to it ("شوكولاته") by edit distance.
 */
class SearchVocabulary
{
    public const CACHE_KEY = 'catalog:search-vocabulary';

    /** Closest corrections kept per keyword word. */
    protected const MAX_CORRECTIONS = 5;

    /**
     * The token itself plus the known words within its typo distance.
     *
     * @return array<int, string>
     */
    public function expand(string $token): array
    {
        $max = SearchText::maxDistance($token);

        if ($max === 0) {
            return [$token];
        }

        $matches = [];

        foreach ($this->words() as $word) {
            if ($word === $token || str_contains($word, $token)) {
                continue; // already matched by the token's own LIKE
            }

            $distance = SearchText::distance($token, $word, $max);

            if ($distance <= $max) {
                $matches[$word] = $distance;
            }
        }

        asort($matches);

        return [$token, ...array_slice(array_keys($matches), 0, self::MAX_CORRECTIONS)];
    }

    /**
     * @return array<int, string>
     */
    public function words(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('stores.search.vocabulary_ttl', 600), function () {
            $names = DB::table('products')->pluck('name')
                ->merge(DB::table('store_profiles')->whereNotNull('store_name')->pluck('store_name'));

            return $names
                ->flatMap(fn (string $name) => SearchText::tokens($name, PHP_INT_MAX))
                ->filter(fn (string $word) => mb_strlen($word) >= SearchText::MIN_FUZZY_LENGTH - 1)
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
