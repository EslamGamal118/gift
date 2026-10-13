<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money transferred to a personal shopper, logged by an admin. Settles the
 * custom orders in `items` (see App\Services\ShopperPayoutService).
 */
class ShopperPayout extends Model
{
    protected $fillable = [
        'shopper_profile_id',
        'amount',
        'currency',
        'orders_count',
        'method',
        'reference',
        'receipt_path',
        'notes',
        'transferred_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'orders_count' => 'integer',
        'transferred_at' => 'datetime',
    ];

    public function shopperProfile(): BelongsTo
    {
        return $this->belongsTo(ShopperProfile::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopperPayoutItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
