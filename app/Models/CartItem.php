<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One product line in a cart, with optional add-ons.
 *
 * Prices are always read live from the catalogue; they are only frozen when
 * the order is placed (see OrderItem).
 *
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int $quantity
 */
class CartItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Add-ons selected for this line (applied per unit).
     */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'cart_item_addon')->withTimestamps();
    }

    /**
     * Price of one unit including its add-ons.
     */
    public function unitPrice(): float
    {
        return (float) $this->product->price;
    }

    public function addonsUnitTotal(): float
    {
        return round((float) $this->addons->sum('price'), 2);
    }

    public function addonsTotal(): float
    {
        return round($this->addonsUnitTotal() * $this->quantity, 2);
    }

    public function lineTotal(): float
    {
        return round(($this->unitPrice() * $this->quantity) + $this->addonsTotal(), 2);
    }
}
