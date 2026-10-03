<?php

namespace App\Models;

use App\Models\Concerns\Favoritable;
use App\Models\Concerns\SmartSearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * A product sold by a merchant (store) account.
 *
 * @property int         $id
 * @property int         $store_id
 * @property int         $category_id
 * @property string      $name
 * @property string|null $description
 * @property string      $price
 * @property int         $stock_quantity
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string|null $image
 * @property string      $rating_avg
 * @property int         $rating_count
 * @property int         $preparation_time  Minutes needed to prepare the product
 * @property bool        $is_featured       Shown in the home screen "featured" section
 */
class Product extends Model
{
    use Favoritable, HasFactory, SmartSearchable;

    /** Name column feeding the search typo vocabulary (SmartSearchable). */
    protected string $searchVocabularyColumn = 'name';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'price',
        'stock_quantity',
        'expiry_date',
        'gift_validity_days',
        'image',
        'preparation_time',
        'rating_avg',
        'rating_count',
        'is_featured',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price'            => 'decimal:2',
        'stock_quantity'   => 'integer',
        'expiry_date'      => 'date',
        'gift_validity_days' => 'integer',
        'preparation_time' => 'integer',
        'rating_avg'       => 'decimal:2',
        'rating_count'     => 'integer',
        'is_featured'      => 'boolean',
    ];

    /**
     * The merchant account that owns this product.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(User::class, 'store_id');
    }

    /**
     * The store profile of the merchant that owns this product.
     */
    public function storeProfile(): HasOneThrough
    {
        return $this->hasOneThrough(StoreProfile::class, User::class, 'id', 'user_id', 'store_id', 'id');
    }

    /**
     * Order lines this product was sold on.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The category this product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Add-ons that can be attached to this product: the owning store's active add-ons
     * linked to the product's category.
     */
    public function availableAddons(): Builder
    {
        return Addon::query()
            ->forStore($this->store_id)
            ->active()
            ->forCategory($this->category_id);
    }

    /**
     * Products visible to customers: owned by an approved store with an active account.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereHas('store', function (Builder $q) {
            $q->where('status', 'active')
                ->whereHas('storeProfile', fn (Builder $p) => $p->where('status', 'approved'));
        });
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Products in any of the given categories.
     *
     * @param  array<int, int>  $categoryIds
     */
    public function scopeInCategories(Builder $query, array $categoryIds): Builder
    {
        return $query->whereIn('products.category_id', $categoryIds);
    }

    /**
     * Products curated for the home screen.
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('products.is_featured', true);
    }

    /**
     * Products of "special" categories (digital / online-only gifts).
     */
    public function scopeOnlineGifts(Builder $query): Builder
    {
        return $query->whereHas('category', fn (Builder $q) => $q->active()->special());
    }

    /**
     * Adds `sold_quantity` (units on paid orders) and sorts by it, best sellers first.
     * Products that never sold fall back to their rating.
     */
    public function scopeBestSellers(Builder $query): Builder
    {
        return $query
            ->withSum(['orderItems as sold_quantity' => fn (Builder $q) => $q->whereHas(
                'order',
                fn (Builder $o) => $o->visibleToStore()->where('status', '!=', Order::STATUS_CANCELLED)
            )], 'quantity')
            ->orderByDesc('sold_quantity')
            ->orderByDesc('rating_count')
            ->orderByDesc('rating_avg');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopePriceBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        return $query
            ->when($min !== null, fn (Builder $q) => $q->where('price', '>=', $min))
            ->when($max !== null, fn (Builder $q) => $q->where('price', '<=', $max));
    }

    /**
     * Products that have no expiry date or whose expiry date is still in the future.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
        });
    }

    /**
     * Match the keyword against the product name only.
     */
    public function scopeSearchName(Builder $query, string $term): Builder
    {
        return $query->where('name', 'like', "%{$term}%");
    }

    /**
     * Match the keyword against the product name or description: Arabic
     * normalized, word order free and typo tolerant (see SmartSearchable).
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->smartSearch($term);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast() && ! $this->expiry_date->isToday();
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }
}
