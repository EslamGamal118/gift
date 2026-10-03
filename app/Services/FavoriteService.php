<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Support\CustomerLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;

/**
 * Customer favorites (stores and products): toggling, listing, and the
 * `is_favorite` flag shown on every store / product the viewer sees.
 *
 * Registered as a scoped singleton: the viewer's favorite ids are loaded at
 * most once per type per request, so resources can ask per item without N+1.
 */
class FavoriteService
{
    /**
     * Sorts where favorites are boosted to the top. Explicit orderings the
     * customer picked (price, name) are left untouched.
     */
    public const BOOSTED_SORTS = ['nearest', 'rating', 'newest', 'best_sellers'];

    /**
     * @var array<string, array<int, true>>  type => [favoritable_id => true]
     */
    protected array $ids = [];

    /**
     * Signed-in user whose favorites shape the current request (null for guests).
     */
    public function viewerId(): ?int
    {
        $id = auth('sanctum')->id();

        return $id !== null ? (int) $id : null;
    }

    /**
     * Viewer id when favorites should be boosted for this sort, else null
     * (so the favoritesFirst() scope becomes a no-op).
     */
    public function boostFor(?string $sort): ?int
    {
        return in_array($sort, self::BOOSTED_SORTS, true) ? $this->viewerId() : null;
    }

    /**
     * Whether the viewer favorited the store / product. Uses the `is_favorite`
     * column when the query selected it, else one lookup per type per request.
     */
    public function isFavorite(Model $model): bool
    {
        $flag = $model->getAttributes()['is_favorite'] ?? null;

        if ($flag !== null) {
            return (bool) $flag;
        }

        $userId = $this->viewerId();

        if ($userId === null) {
            return false;
        }

        $type = $model->getMorphClass();

        $this->ids[$type] ??= Favorite::query()
            ->forUser($userId)
            ->ofType($type)
            ->pluck('favoritable_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        return isset($this->ids[$type][(int) $model->getKey()]);
    }

    /**
     * Add the item to the user's favorites, or remove it when already there.
     * Removing works even if the item is no longer visible; adding requires a
     * visible store / product (404 otherwise).
     *
     * @return bool the new state: true = favorite
     *
     * @throws ModelNotFoundException
     */
    public function toggle(User $user, string $type, int $id): bool
    {
        $removed = Favorite::query()->forUser($user->id)->ofType($type)->where('favoritable_id', $id)->delete();

        if ($removed > 0) {
            $this->remember($type, $id, false);

            return false;
        }

        $this->visibleQuery($type)->whereKey($id)->firstOrFail();

        // Unique index + createOrFirst: a double tap cannot create two rows.
        Favorite::query()->createOrFirst([
            'user_id'          => $user->id,
            'favoritable_type' => $type,
            'favoritable_id'   => $id,
        ]);

        $this->remember($type, $id, true);

        return true;
    }

    /**
     * The user's favorite stores or products that are still visible, most
     * recently added first. Stores get `distance_km` when a location is known.
     */
    public function paginate(User $user, string $type, int $perPage, ?CustomerLocation $location = null): LengthAwarePaginator
    {
        $model = new (Favorite::TYPES[$type]);
        $table = $model->getTable();

        $query = $this->visibleQuery($type)
            ->select("{$table}.*")
            ->selectRaw('1 as is_favorite')
            ->join('favorites', function (JoinClause $join) use ($table, $type, $user) {
                $join->on('favorites.favoritable_id', '=', "{$table}.id")
                    ->where('favorites.favoritable_type', $type)
                    ->where('favorites.user_id', $user->id);
            })
            ->orderByDesc('favorites.id');

        if ($type === Favorite::TYPE_STORE) {
            $query->with(['category', 'mainBranch'])
                ->when($location, fn (Builder $q) => $q->withDistanceTo($location->latitude, $location->longitude));
        } else {
            $query->with(['category', 'storeProfile']);
        }

        return $query->paginate($perPage);
    }

    protected function visibleQuery(string $type): Builder
    {
        return match ($type) {
            Favorite::TYPE_STORE   => StoreProfile::query()->visible(),
            Favorite::TYPE_PRODUCT => Product::query()->visible(),
        };
    }

    /**
     * Keep the per-request cache in step with a toggle.
     */
    protected function remember(string $type, int $id, bool $favorite): void
    {
        if (! isset($this->ids[$type])) {
            return;
        }

        if ($favorite) {
            $this->ids[$type][$id] = true;
        } else {
            unset($this->ids[$type][$id]);
        }
    }
}
