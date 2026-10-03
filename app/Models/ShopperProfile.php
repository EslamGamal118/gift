<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string|null $personal_photo
 * @property string|null $bio
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string      $rating_avg
 * @property int         $rating_count
 * @property bool        $is_available  Online / accepting custom orders right now
 * @property string      $status        draft | pending | approved | rejected
 */
class ShopperProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'personal_photo',
        'bio',
        'iban',
        'iban_certificate_file',
        'national_id_number',
        'national_id_file',
        'driving_license_file',
        'freelance_license_file',
        'address',
        'latitude',
        'longitude',
        'rating_avg',
        'rating_count',
        'is_available',
        'status',
        'submitted_at',
        'rejection_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'rating_avg' => 'decimal:2',
        'rating_count' => 'integer',
        'is_available' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    /**
     * حساب المتسوق
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * التصنيفات التي يعمل بها المتسوق
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_shopper_profile')
            ->withTimestamps();
    }

    /**
     * Custom orders assigned to this shopper (through the account).
     */
    public function customOrders(): HasMany
    {
        return $this->hasMany(CustomOrder::class, 'shopper_id', 'user_id');
    }

    /**
     * Shoppers customers may pick: approved profile with an active account.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('shopper_profiles.status', 'approved')
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'));
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('shopper_profiles.is_available', true);
    }

    /**
     * Shoppers working in the given category (specialty).
     */
    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $categoryId));
    }

    public function scopeMinRating(Builder $query, float $rating): Builder
    {
        return $query->where('shopper_profiles.rating_avg', '>=', $rating);
    }

    /**
     * Match the keyword against the shopper's name or bio.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('shopper_profiles.bio', 'like', "%{$term}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$term}%"));
        });
    }

    /**
     * Add `distance_km` from the given point to the shopper's saved position
     * (null when the shopper has no position).
     */
    public function scopeWithDistanceTo(Builder $query, float $latitude, float $longitude): Builder
    {
        [$sql, $bindings] = Geo::distanceSql($latitude, $longitude, 'shopper_profiles');

        if (empty($query->getQuery()->columns)) {
            $query->select('shopper_profiles.*');
        }

        return $query->selectRaw(
            "CASE WHEN shopper_profiles.latitude IS NULL OR shopper_profiles.longitude IS NULL THEN NULL ELSE ROUND({$sql}, 2) END as distance_km",
            $bindings
        );
    }

    /**
     * Add `completed_orders_count`: custom orders this shopper finished.
     */
    public function scopeWithCompletedOrdersCount(Builder $query): Builder
    {
        return $query->withCount([
            'customOrders as completed_orders_count' => fn (Builder $q) => $q->where('status', CustomOrder::STATUS_COMPLETED),
        ]);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
