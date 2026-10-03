<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the customer wants bought: a free-text product, how many, the
 * price they expect, and reference images.
 *
 * @property int $id
 * @property int $custom_order_id
 * @property string $product_name
 * @property string|null $description
 * @property int $quantity
 * @property string|null $expected_price_min
 * @property string|null $expected_price_max
 * @property string|null $unit_price  price actually paid per unit (set when purchased)
 * @property int $sort_order
 */
class CustomOrderItem extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'custom_order_id',
        'product_name',
        'description',
        'quantity',
        'expected_price_min',
        'expected_price_max',
        'unit_price',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity'           => 'integer',
        'expected_price_min' => 'decimal:2',
        'expected_price_max' => 'decimal:2',
        'unit_price'         => 'decimal:2',
        'sort_order'         => 'integer',
    ];

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(CustomOrderItemMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Products the shopper suggested instead of this item, newest first.
     */
    public function alternatives(): HasMany
    {
        return $this->hasMany(CustomOrderAlternative::class)->latest('id');
    }

    /**
     * Expected line total range (unit range x quantity); null bounds stay null.
     *
     * @return array{min: float|null, max: float|null}
     */
    public function expectedTotalRange(): array
    {
        return [
            'min' => $this->expected_price_min !== null ? round((float) $this->expected_price_min * $this->quantity, 2) : null,
            'max' => $this->expected_price_max !== null ? round((float) $this->expected_price_max * $this->quantity, 2) : null,
        ];
    }

    /**
     * What the shopper paid for the line (unit price x quantity), null until priced.
     */
    public function totalPrice(): ?float
    {
        return $this->unit_price !== null ? round((float) $this->unit_price * $this->quantity, 2) : null;
    }
}
