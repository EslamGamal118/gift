<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A customer's rating of a store.
 *
 * Saving or deleting a review re-derives the store's `rating_avg` and
 * `rating_count`, so the listing / show endpoints never aggregate on the fly.
 *
 * @property int         $id
 * @property int         $store_profile_id
 * @property int         $user_id
 * @property int|null    $order_id
 * @property int         $rating      1..5
 * @property string|null $comment
 * @property bool        $is_visible
 */
class StoreReview extends Model
{
    use HasFactory;

    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'store_profile_id',
        'user_id',
        'order_id',
        'rating',
        'comment',
        'is_visible',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rating'     => 'integer',
        'is_visible' => 'boolean',
    ];

    protected static function booted(): void
    {
        $recalculate = function (self $review): void {
            self::recalculateFor($review->store_profile_id);

            // Moving a review to another store must fix both aggregates.
            if ($review->wasChanged('store_profile_id') && $review->getOriginal('store_profile_id')) {
                self::recalculateFor((int) $review->getOriginal('store_profile_id'));
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function storeProfile(): BelongsTo
    {
        return $this->belongsTo(StoreProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function scopeForStore(Builder $query, int $storeProfileId): Builder
    {
        return $query->where('store_profile_id', $storeProfileId);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Aggregates
    |--------------------------------------------------------------------------
    */

    /**
     * Re-derive the denormalised rating columns of a store from its visible reviews.
     */
    public static function recalculateFor(int $storeProfileId): void
    {
        $stats = static::query()
            ->forStore($storeProfileId)
            ->visible()
            ->selectRaw('COUNT(*) as total, COALESCE(AVG(rating), 0) as average')
            ->first();

        StoreProfile::query()->whereKey($storeProfileId)->update([
            'rating_avg'   => round((float) $stats->average, 2),
            'rating_count' => (int) $stats->total,
        ]);
    }

    /**
     * Number of visible reviews per star for a store, always keyed 1..5.
     *
     * @return array<int, int>
     */
    public static function breakdownFor(int $storeProfileId): array
    {
        $counts = static::query()
            ->forStore($storeProfileId)
            ->visible()
            ->select('rating', DB::raw('COUNT(*) as total'))
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $breakdown = [];

        for ($star = self::MAX_RATING; $star >= self::MIN_RATING; $star--) {
            $breakdown[$star] = (int) ($counts[$star] ?? 0);
        }

        return $breakdown;
    }
}
