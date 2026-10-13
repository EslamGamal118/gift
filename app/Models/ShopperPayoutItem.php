<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One custom order settled by a shopper payout, with its amounts frozen at payout time.
 */
class ShopperPayoutItem extends Model
{
    protected $fillable = [
        'shopper_payout_id',
        'custom_order_id',
        'items_cost',
        'shopper_fee',
        'commission_rate',
        'commission_amount',
        'shopper_amount',
    ];

    protected $casts = [
        'items_cost' => 'decimal:2',
        'shopper_fee' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'shopper_amount' => 'decimal:2',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(ShopperPayout::class, 'shopper_payout_id');
    }

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class);
    }
}
