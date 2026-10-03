<?php

namespace App\Http\Resources\Concerns;

use App\Services\FavoriteService;

/**
 * `is_favorite` for store / product resources: false for guests, otherwise
 * read from the query's `is_favorite` column or the per-request favorites
 * cache (one query per type per request, never per item).
 */
trait ResolvesFavoriteState
{
    protected function isFavorite(): bool
    {
        return app(FavoriteService::class)->isFavorite($this->resource);
    }
}
