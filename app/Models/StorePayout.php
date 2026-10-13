<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money transferred to a store, logged by an admin. Settles the orders in
 * `items` (see App\Services\StorePayoutService).
 *
 * @property int $id
 * @property int $store_profile_id
 * @property string $amount
 * @property string $method
 * @property string|null $reference
 * @property string|null $receipt_path
 * @property \Illuminate\Support\Carbon $transferred_at
 */
class StorePayout extends Model
{
    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_WALLET = 'wallet';

    public const METHOD_CASH = 'cash';

    public const METHOD_OTHER = 'other';

    public const METHODS = [self::METHOD_BANK_TRANSFER, self::METHOD_WALLET, self::METHOD_CASH, self::METHOD_OTHER];

    protected $fillable = [
        'store_profile_id',
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

    public function storeProfile(): BelongsTo
    {
        return $this->belongsTo(StoreProfile::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StorePayoutItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
