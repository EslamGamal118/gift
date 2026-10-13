<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

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
        'city',
        'commission_rate',
        'suspension_reason',
        'suspended_at',
        'admin_notes',
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
        'commission_rate' => 'decimal:2',
        'suspended_at' => 'datetime',
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
     * Customer reviews of the shopper (one per reviewed custom order).
     */
    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(Review::class, CustomOrder::class, 'shopper_id', 'reviewable_id', 'user_id', 'id')
            ->where('reviews.reviewable_type', Review::TYPE_CUSTOM_ORDER);
    }

    /**
     * Money transferred to the shopper (admin payout ledger).
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(ShopperPayout::class);
    }

    /**
     * Financial totals and order counts as subselects (one query for a whole list):
     * - unsettled_*: paid, non-cancelled custom orders not paid out yet
     * - due_*: those of them completed (payable now)
     * - settled_*: amounts frozen when orders were paid out
     * Read them through financials().
     */
    public function scopeWithFinancials(Builder $query): Builder
    {
        $orders = fn ($q) => $q->from('custom_orders')->whereColumn('custom_orders.shopper_id', 'shopper_profiles.user_id');

        $unsettled = fn ($q) => $orders($q)
            ->where('custom_orders.payment_status', CustomOrder::PAYMENT_PAID)
            ->where('custom_orders.status', '!=', CustomOrder::STATUS_CANCELLED)
            ->whereNotExists(fn ($items) => $items->from('shopper_payout_items')->whereColumn('shopper_payout_items.custom_order_id', 'custom_orders.id'));

        $due = fn ($q) => $unsettled($q)->where('custom_orders.status', CustomOrder::STATUS_COMPLETED);

        $fee = 'COALESCE(SUM(custom_orders.shopper_fees), 0)';
        $items = 'COALESCE(SUM(GREATEST(COALESCE(custom_orders.final_amount, 0) - COALESCE(custom_orders.shopper_fees, 0), 0)), 0)';

        $settled = fn (string $column) => fn ($q) => $q->from('shopper_payout_items')
            ->join('shopper_payouts', 'shopper_payouts.id', '=', 'shopper_payout_items.shopper_payout_id')
            ->whereColumn('shopper_payouts.shopper_profile_id', 'shopper_profiles.id')
            ->selectRaw("COALESCE(SUM(shopper_payout_items.{$column}), 0)");

        if ($query->getQuery()->columns === null) {
            $query->select('shopper_profiles.*');
        }

        return $query
            ->selectSub(fn ($q) => $orders($q)->where('custom_orders.status', '!=', CustomOrder::STATUS_DRAFT)->selectRaw('COUNT(*)'), 'assigned_orders_count')
            ->selectSub(fn ($q) => $orders($q)->where('custom_orders.status', CustomOrder::STATUS_COMPLETED)->selectRaw('COUNT(*)'), 'completed_orders_count')
            ->selectSub(fn ($q) => $unsettled($q)->selectRaw($fee), 'unsettled_fee')
            ->selectSub(fn ($q) => $due($q)->selectRaw($fee), 'due_fee')
            ->selectSub(fn ($q) => $due($q)->selectRaw($items), 'due_items_cost')
            ->selectSub(fn ($q) => $due($q)->selectRaw('COUNT(*)'), 'due_orders_count')
            ->selectSub($settled('shopper_fee'), 'settled_fee')
            ->selectSub($settled('commission_amount'), 'settled_commission')
            ->selectSub(fn ($q) => $q->from('shopper_payouts')->whereColumn('shopper_payouts.shopper_profile_id', 'shopper_profiles.id')->selectRaw('COALESCE(SUM(amount), 0)'), 'transferred_total');
    }

    /**
     * Earnings and balances. The shopper earns their fee minus the platform
     * commission; the items they bought are reimbursed in full on top. Orders
     * not paid out yet use the current rate; paid-out ones keep their frozen amounts.
     *
     * @return array{assigned_orders: int, completed_orders: int, net_earnings: float, commission: float, available: float, due_orders_count: int, transferred: float}
     */
    public function financials(): array
    {
        $shopper = array_key_exists('due_fee', $this->getAttributes())
            ? $this
            : static::withTrashed()->withFinancials()->findOrFail($this->getKey());

        $rate = (float) $this->commission_rate / 100;
        $unsettledFee = (float) $shopper->unsettled_fee;

        return [
            'assigned_orders' => (int) $shopper->assigned_orders_count,
            'completed_orders' => (int) $shopper->completed_orders_count,
            'net_earnings' => round((float) $shopper->settled_fee - (float) $shopper->settled_commission + $unsettledFee * (1 - $rate), 2),
            'commission' => round((float) $shopper->settled_commission + $unsettledFee * $rate, 2),
            'available' => round((float) $shopper->due_items_cost + (float) $shopper->due_fee * (1 - $rate), 2),
            'due_orders_count' => (int) $shopper->due_orders_count,
            'transferred' => round((float) $shopper->transferred_total, 2),
        ];
    }

    /**
     * What one custom order is worth to the shopper at the current commission rate.
     *
     * @return array{items_cost: float, shopper_fee: float, commission_amount: float, shopper_amount: float}
     */
    public function shareOf(CustomOrder $order): array
    {
        $fee = round((float) $order->shopper_fees, 2);
        $itemsCost = round(max((float) $order->final_amount - $fee, 0), 2);
        $commission = round($fee * (float) $this->commission_rate / 100, 2);

        return [
            'items_cost' => $itemsCost,
            'shopper_fee' => $fee,
            'commission_amount' => $commission,
            'shopper_amount' => round($itemsCost + $fee - $commission, 2),
        ];
    }

    /**
     * Suspended = the owner account is blocked: the shopper disappears from the
     * app (scopeVisible) and cannot sign in.
     */
    public function isSuspended(): bool
    {
        return $this->user?->status === 'blocked';
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
