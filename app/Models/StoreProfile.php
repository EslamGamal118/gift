<?php

namespace App\Models;

use App\Models\Concerns\Favoritable;
use App\Models\Concerns\SmartSearchable;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class StoreProfile extends Model
{
    use Favoritable, HasFactory, SmartSearchable;

    /** Name column feeding the search typo vocabulary (SmartSearchable). */
    protected string $searchVocabularyColumn = 'store_name';

    /**
     * أيام الأسبوع بترتيب العرض في التطبيق (يبدأ الأسبوع بالسبت)
     *
     * @var array<int, string>
     */
    public const DAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'store_name',
        'category_id',
        'phone',
        'email',
        'description',
        'iban',
        'iban_certificate_file',
        'logo',
        'cover_image',
        'commercial_register_file',
        'working_hours',
        'delivery_fee',
        'preparation_time',
        'rating_avg',
        'rating_count',
        'is_featured',
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
        'working_hours' => 'array',
        'delivery_fee' => 'decimal:2',
        'preparation_time' => 'integer',
        'rating_avg' => 'decimal:2',
        'rating_count' => 'integer',
        'is_featured' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    /**
     * صاحب المتجر
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * تصنيف المتجر
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(StoreBranch::class);
    }

    /**
     * الفرع الرئيسي
     */
    public function mainBranch(): HasOne
    {
        return $this->hasOne(StoreBranch::class)->where('is_main', true);
    }

    /**
     * The store's map location lives on its main branch: update it, or create
     * the main branch on first use. Null values (optional name / phone) are ignored.
     *
     * @param  array{address?: ?string, latitude?: mixed, longitude?: mixed, name?: ?string, phone?: ?string}  $data
     */
    public function saveMainLocation(array $data): StoreBranch
    {
        $data = array_filter($data, fn ($value) => $value !== null);

        /** @var StoreBranch|null $main */
        $main = $this->branches()->main()->first();

        if ($main) {
            $main->fill($data)->save();

            return $main;
        }

        /** @var StoreBranch $branch */
        $branch = $this->branches()->create($data + [
            'name'      => $data['name'] ?? $this->store_name ?? __('profile.main_branch'),
            'is_main'   => false,
            'is_active' => true,
        ]);

        $branch->markAsMain();

        return $branch;
    }

    /**
     * Products offered by the store (owned through the merchant account).
     */
    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, User::class, 'id', 'store_id', 'user_id', 'id');
    }

    /**
     * Orders placed with the store (keyed by the merchant account).
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'store_id', 'user_id');
    }

    /**
     * Customer reviews of the store.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(StoreReview::class);
    }

    /**
     * Stores visible to customers: approved profile with an active owner account.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('store_profiles.status', 'approved')
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'));
    }

    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('store_profiles.category_id', $categoryId);
    }

    /**
     * Stores filed under any of the categories, or selling at least one current
     * (non-expired) product in one of them. Backed by products(category_id, store_id).
     *
     * @param  int|array<int, int>  $categoryIds
     */
    public function scopeOfferingCategory(Builder $query, int|array $categoryIds): Builder
    {
        $categoryIds = (array) $categoryIds;

        return $query->where(function (Builder $q) use ($categoryIds) {
            $q->whereIn('store_profiles.category_id', $categoryIds)
                ->orWhereExists(fn ($products) => $this->currentProducts($products, $categoryIds)->selectRaw('1'));
        });
    }

    /**
     * Add `starting_price`: the store's cheapest current product, in the given
     * categories when any (null when it sells nothing there).
     *
     * @param  array<int, int>  $categoryIds
     */
    public function scopeWithStartingPrice(Builder $query, array $categoryIds = []): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('store_profiles.*');
        }

        return $query->selectSub(fn ($products) => $this->currentProducts($products, $categoryIds)->selectRaw('MIN(products.price)'), 'starting_price');
    }

    /**
     * Correlated subquery over the store's non-expired products.
     *
     * @param  \Illuminate\Database\Query\Builder  $products
     * @param  array<int, int>  $categoryIds
     * @return \Illuminate\Database\Query\Builder
     */
    protected function currentProducts($products, array $categoryIds)
    {
        return $products->from('products')
            ->whereColumn('products.store_id', 'store_profiles.user_id')
            ->when($categoryIds !== [], fn ($p) => $p->whereIn('products.category_id', $categoryIds))
            ->where(fn ($e) => $e->whereNull('products.expiry_date')->orWhereDate('products.expiry_date', '>=', now()->toDateString()));
    }

    /**
     * Match the keyword against the store name or description: Arabic
     * normalized, word order free and typo tolerant (see SmartSearchable).
     */
    public function scopeSearchName(Builder $query, string $term): Builder
    {
        return $query->smartSearch($term);
    }

    public function scopeMinRating(Builder $query, float $rating): Builder
    {
        return $query->where('rating_avg', '>=', $rating);
    }

    /**
     * Add `sales_count`: delivered orders of the store, the "best selling"
     * ranking signal. Counted from the orders(store_id, status) index alone.
     */
    public function scopeWithSalesCount(Builder $query): Builder
    {
        return $query->withCount(['orders as sales_count' => fn (Builder $q) => $q->where('status', Order::STATUS_DELIVERED)]);
    }

    /**
     * Stores curated for the home screen.
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('store_profiles.is_featured', true);
    }

    /**
     * Stores that have at least one active branch in the given city key.
     */
    public function scopeInCity(Builder $query, string $city): Builder
    {
        return $query->whereHas('branches', fn (Builder $q) => $q->active()->where('city', $city));
    }

    /**
     * Stores with an active, geo-located branch inside the bounding box of
     * `$km` road km around the point. This is the cheap index-backed pre-filter
     * that runs before the exact ST_Distance_Sphere distance; `$km` is turned
     * back into its straight-line radius so the box stays tight.
     */
    public function scopeWithinBoundingBox(Builder $query, float $latitude, float $longitude, float $km): Builder
    {
        $straightKm = $km / Geo::roadFactor();

        return $query->whereHas('branches', fn (Builder $q) => $q->withinBoundingBox($latitude, $longitude, $straightKm));
    }

    /**
     * Nearby stores, nearest first: bounding-box pre-filter, road distance to
     * the nearest branch as `distance_km`, then a hard radius cut.
     */
    public function scopeNearby(Builder $query, float $latitude, float $longitude, float $km): Builder
    {
        return $query
            ->withinBoundingBox($latitude, $longitude, $km)
            ->withDistanceTo($latitude, $longitude)
            ->withinKm($km)
            ->orderBy('distance_km')
            ->orderByDesc('rating_avg');
    }

    /**
     * Add a `distance_km` column: approximate driving distance from the given
     * point to the nearest active branch of the store, i.e. the ST_Distance_Sphere
     * straight line times the road factor (null when no branch is located).
     */
    public function scopeWithDistanceTo(Builder $query, float $latitude, float $longitude): Builder
    {
        [$sql, $bindings] = Geo::distanceSql($latitude, $longitude, 'store_branches');
        $factor = Geo::roadFactor();

        $nearest = StoreBranch::query()
            ->selectRaw("ROUND({$sql} * {$factor}, 2)", $bindings)
            ->whereColumn('store_branches.store_profile_id', 'store_profiles.id')
            ->where('store_branches.is_active', true)
            ->whereNotNull('store_branches.latitude')
            ->whereNotNull('store_branches.longitude')
            ->orderByRaw($sql, $bindings)
            ->limit(1);

        if (empty($query->getQuery()->columns)) {
            $query->select('store_profiles.*');
        }

        return $query->selectSub($nearest, 'distance_km');
    }

    /**
     * Restrict to stores whose nearest branch is within the given road km (requires withDistanceTo).
     */
    public function scopeWithinKm(Builder $query, float $km): Builder
    {
        return $query->having('distance_km', '<=', $km);
    }

    /**
     * Whether the store is open right now according to its weekly schedule.
     * Closing times past midnight (e.g. 16:00 -> 02:00) are handled.
     */
    public function isOpenNow(?CarbonInterface $at = null): bool
    {
        $at    = Carbon::instance($at ?? now())->setTimezone(config('stores.timezone', config('app.timezone')));
        $hours = $this->working_hours ?? [];

        $today     = strtolower($at->englishDayOfWeek);
        $yesterday = strtolower($at->copy()->subDay()->englishDayOfWeek);
        $time      = $at->format('H:i');

        if ($this->isWithinSlot($hours[$today] ?? null, $time, false)) {
            return true;
        }

        // Still inside the overnight slot of the previous day (e.g. 16:00 -> 02:00 at 01:00).
        return $this->isWithinSlot($hours[$yesterday] ?? null, $time, true);
    }

    /**
     * @param  array{is_open?: bool, from?: ?string, to?: ?string}|null  $slot
     */
    protected function isWithinSlot(?array $slot, string $time, bool $overnightTail): bool
    {
        if (! $slot || ! filter_var($slot['is_open'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $from = $slot['from'] ?? null;
        $to   = $slot['to'] ?? null;

        if (! $from || ! $to) {
            return false;
        }

        $overnight = $to < $from;

        if ($overnightTail) {
            return $overnight && $time < $to;
        }

        return $overnight
            ? $time >= $from
            : ($time >= $from && $time < $to);
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
