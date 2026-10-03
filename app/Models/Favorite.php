<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A store or product the customer marked as favorite.
 *
 * @property int $id
 * @property int $user_id
 * @property string $favoritable_type store | product (morph alias, see AppServiceProvider)
 * @property int $favoritable_id
 */
class Favorite extends Model
{
    public const TYPE_STORE = 'store';

    public const TYPE_PRODUCT = 'product';

    /**
     * Morph alias => model. Registered as the morph map, so these aliases are
     * what `favoritable_type` stores and what the API accepts.
     */
    public const TYPES = [
        self::TYPE_STORE   => StoreProfile::class,
        self::TYPE_PRODUCT => Product::class,
    ];

    protected $fillable = [
        'user_id',
        'favoritable_type',
        'favoritable_id',
    ];

    protected $casts = [
        'user_id'        => 'integer',
        'favoritable_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('favoritable_type', $type);
    }
}
