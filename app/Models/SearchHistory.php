<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A keyword a user searched for (one row per keyword per user, most recent first).
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $keyword
 * @property string      $type
 * @property int|null    $results_count
 */
class SearchHistory extends Model
{
    use HasFactory;

    public const TYPE_ALL        = 'all';
    public const TYPE_STORES     = 'stores';
    public const TYPE_PRODUCTS   = 'products';
    public const TYPE_CATEGORIES = 'categories';

    public const TYPES = [
        self::TYPE_ALL,
        self::TYPE_STORES,
        self::TYPE_PRODUCTS,
        self::TYPE_CATEGORIES,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'keyword',
        'type',
        'results_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'results_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Most recently searched first.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Record a search for the user, bumping an existing keyword to the top and
     * pruning entries beyond the configured limit.
     */
    public static function record(User $user, string $keyword, string $type = self::TYPE_ALL, ?int $resultsCount = null): self
    {
        $keyword = self::normalizeKeyword($keyword);

        /** @var self $entry */
        $entry = static::query()->firstOrNew(['user_id' => $user->id, 'keyword' => $keyword]);

        $entry->fill(['type' => $type, 'results_count' => $resultsCount]);
        // Set explicitly so a repeated identical search still moves to the top.
        $entry->updated_at = now();
        $entry->save();

        static::prune($user);

        return $entry;
    }

    /**
     * Keep only the most recent entries for the user.
     */
    public static function prune(User $user, ?int $limit = null): void
    {
        $limit ??= (int) config('stores.search.history_limit', 20);

        $stale = static::query()
            ->where('user_id', $user->id)
            ->recent()
            ->skip($limit)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            static::query()->whereIn('id', $stale)->delete();
        }
    }

    public static function normalizeKeyword(string $keyword): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $keyword)), 0, 100);
    }
}
