<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * A shopper's offer on a custom order that was opened for bidding.
 *
 * @property int $id
 * @property int $custom_order_id
 * @property int $shopper_id
 * @property string $amount
 * @property string $service_fee
 * @property Carbon|null $delivery_at
 * @property string|null $message
 * @property string $status pending | accepted | rejected | withdrawn
 * @property Carbon|null $responded_at
 */
class CustomOrderBid extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'custom_order_id',
        'shopper_id',
        'amount',
        'service_fee',
        'delivery_at',
        'message',
        'status',
        'responded_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount'       => 'decimal:2',
        'service_fee'  => 'decimal:2',
        'delivery_at'  => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class);
    }

    public function shopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shopper_id');
    }

    public function shopperProfile(): HasOneThrough
    {
        return $this->hasOneThrough(ShopperProfile::class, User::class, 'id', 'user_id', 'shopper_id', 'id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
