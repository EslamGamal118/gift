<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class StoreBranch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'store_profile_id',
        'name',
        'address',
        'city',
        'latitude',
        'longitude',
        'phone',
        'is_main',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_main' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * The store this branch belongs to.
     */
    public function storeProfile(): BelongsTo
    {
        return $this->belongsTo(StoreProfile::class);
    }

    /**
     * The merchant account that owns the store.
     */
    public function owner(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            StoreProfile::class,
            'id',
            'id',
            'store_profile_id',
            'user_id'
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('is_main', true);
    }

    /**
     * Branches with coordinates (the only ones a distance can be computed for).
     */
    public function scopeLocated(Builder $query): Builder
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /**
     * Active, located branches inside the rectangle enclosing `$km` around the point.
     */
    public function scopeWithinBoundingBox(Builder $query, float $latitude, float $longitude, float $km): Builder
    {
        $box = Geo::boundingBox($latitude, $longitude, $km);

        return $query
            ->active()
            ->located()
            ->whereBetween('latitude', [$box['min_lat'], $box['max_lat']])
            ->whereBetween('longitude', [$box['min_lng'], $box['max_lng']]);
    }

    /**
     * Make this branch the store's only main branch.
     */
    public function markAsMain(): void
    {
        static::query()
            ->where('store_profile_id', $this->store_profile_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_main' => false]);

        if (! $this->is_main) {
            $this->forceFill(['is_main' => true])->save();
        }
    }
}
