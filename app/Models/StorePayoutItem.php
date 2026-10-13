<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order settled by a payout, with its amounts frozen at payout time.
 */
class StorePayoutItem extends Model
{
    protected $fillable = [
        'store_payout_id',
        'order_id',
        'subtotal',
        'commission_rate',
        'commission_amount',
        'store_amount',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'store_amount' => 'decimal:2',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(StorePayout::class, 'store_payout_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
