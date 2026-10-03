<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A reference image / attachment the customer added to a custom order item.
 * The file itself is removed from disk when the row is deleted.
 *
 * @property int $id
 * @property int $custom_order_item_id
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property int $sort_order
 */
class CustomOrderItemMedia extends Model
{
    protected $table = 'custom_order_item_media';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'custom_order_item_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'size'       => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $media): void {
            if (! Str::startsWith($media->path, ['http://', 'https://'])) {
                Storage::disk($media->disk)->delete($media->path);
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomOrderItem::class, 'custom_order_item_id');
    }

    public function url(): string
    {
        if (Str::startsWith($this->path, ['http://', 'https://'])) {
            return $this->path;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    public function isImage(): bool
    {
        return Str::startsWith((string) $this->mime_type, 'image/');
    }
}
