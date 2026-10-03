<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A customer's review of a finished order: a store order (`order`) or a
 * custom order (`custom_order`). `store_rating` rates the store, or the
 * personal shopper for a custom order; `products_rating` the products.
 * Kept in step with the ratings shown elsewhere by ReviewService.
 *
 * @property int $id
 * @property int $user_id
 * @property string $reviewable_type  order | custom_order
 * @property int $reviewable_id
 * @property int $store_rating
 * @property string|null $store_comment
 * @property int $products_rating
 * @property string|null $products_comment
 */
class Review extends Model
{
    use HasFactory;

    public const TYPE_ORDER = 'order';

    public const TYPE_CUSTOM_ORDER = 'custom_order';

    /**
     * Morph aliases stored in `reviewable_type` (registered in AppServiceProvider).
     *
     * @var array<string, class-string<Model>>
     */
    public const TYPES = [
        self::TYPE_ORDER        => Order::class,
        self::TYPE_CUSTOM_ORDER => CustomOrder::class,
    ];

    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'store_rating',
        'store_comment',
        'products_rating',
        'products_comment',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'store_rating'    => 'integer',
        'products_rating' => 'integer',
    ];

    /**
     * The order reviewed (Order or CustomOrder).
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
