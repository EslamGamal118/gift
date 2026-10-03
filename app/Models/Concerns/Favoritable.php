<?php

namespace App\Models\Concerns;

use App\Models\Favorite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A model customers can favorite (stores, products).
 *
 * The scopes add an `is_favorite` column computed by an indexed EXISTS
 * subquery, so listings know the viewer's favorites without extra queries.
 */
trait Favoritable
{
    public static function bootFavoritable(): void
    {
        // Polymorphic rows have no foreign key: drop them with the item.
        static::deleted(fn (self $model) => $model->favorites()->delete());
    }

    public function favorites(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    /**
     * Add `is_favorite` (0/1) for the given user. No-op for guests.
     */
    public function scopeWithFavoriteFlag(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query;
        }

        return $query->withExists(['favorites as is_favorite' => fn (Builder $q) => $q->where('user_id', $userId)]);
    }

    /**
     * Put the user's favorites first. Call before the regular ordering, which
     * then applies inside each group (favorites / others). No-op for guests.
     */
    public function scopeFavoritesFirst(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query;
        }

        return $query->withFavoriteFlag($userId)->orderByDesc('is_favorite');
    }
}
