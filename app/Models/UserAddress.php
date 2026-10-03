<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved delivery address of a customer.
 *
 * @property int $id
 * @property int $user_id
 * @property string $location_name Home, Office, ...
 * @property string $city
 * @property string $district
 * @property string $street
 * @property string $building_number
 * @property string|null $phone
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_default
 */
class UserAddress extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'location_name',
        'city',
        'district',
        'street',
        'building_number',
        'phone',
        'latitude',
        'longitude',
        'is_default',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Make this the customer's only default address.
     */
    public function markAsDefault(): void
    {
        static::query()
            ->where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_default' => false]);

        if (! $this->is_default) {
            $this->forceFill(['is_default' => true])->save();
        }
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Single-line rendering used for order snapshots and gateway payloads.
     */
    public function toLine(): string
    {
        return implode(', ', array_filter([
            $this->building_number,
            $this->street,
            $this->district,
            $this->city,
        ]));
    }
}
