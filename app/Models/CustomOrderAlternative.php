<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A product the shopper suggests instead of an unavailable custom order item.
 * The image file is removed from disk when the row is deleted.
 *
 * @property int $id
 * @property int $custom_order_id
 * @property int $custom_order_item_id
 * @property int $shopper_id
 * @property string $product_name
 * @property string $price
 * @property string $currency
 * @property string $reason
 * @property string $image_disk
 * @property string|null $image_path
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $responded_at
 */
class CustomOrderAlternative extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'custom_order_id',
        'custom_order_item_id',
        'shopper_id',
        'product_name',
        'price',
        'currency',
        'reason',
        'image_disk',
        'image_path',
        'status',
        'responded_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price'        => 'decimal:2',
        'responded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $alternative): void {
            if ($alternative->image_path) {
                Storage::disk($alternative->image_disk)->delete($alternative->image_path);
            }
        });
    }

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomOrderItem::class, 'custom_order_item_id');
    }

    public function shopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shopper_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk($this->image_disk)->url($this->image_path) : null;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
