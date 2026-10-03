<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The customer's single active cart plus the checkout selections made so far.
 *
 * Items may come from several stores; at checkout they are split into one
 * order per store (see CheckoutService::placeOrder). The address, delivery
 * time, promo code and gift message apply to every store's order.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $address_id
 * @property string|null $delivery_type scheduled | instant
 * @property Carbon|null $delivery_date
 * @property int|null $delivery_slot_id
 * @property int|null $promo_code_id
 * @property string|null $gift_message
 */
class Cart extends Model
{
    use HasFactory;

    public const DELIVERY_SCHEDULED = 'scheduled';

    public const DELIVERY_INSTANT = 'instant';

    public const DELIVERY_TYPES = [self::DELIVERY_SCHEDULED, self::DELIVERY_INSTANT];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'address_id',
        'delivery_type',
        'delivery_date',
        'delivery_slot_id',
        'promo_code_id',
        'gift_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'delivery_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    public function deliverySlot(): BelongsTo
    {
        return $this->belongsTo(DeliverySlot::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function isEmpty(): bool
    {
        return $this->items()->doesntExist();
    }

    public function isInstantDelivery(): bool
    {
        return $this->delivery_type === self::DELIVERY_INSTANT;
    }

    public function hasDeliverySelection(): bool
    {
        if ($this->delivery_type === self::DELIVERY_INSTANT) {
            return true;
        }

        return $this->delivery_type === self::DELIVERY_SCHEDULED
            && $this->delivery_date !== null
            && $this->delivery_slot_id !== null;
    }

    /**
     * Sum of all lines (products + add-ons) at current catalogue prices.
     */
    public function subtotal(): float
    {
        $this->loadMissing(['items.product', 'items.addons']);

        return self::linesTotal($this->items);
    }

    /**
     * Sum of some lines (e.g. one store's share of the cart).
     *
     * @param  Collection<int, CartItem>  $items
     */
    public static function linesTotal(Collection $items): float
    {
        return round($items->sum(fn (CartItem $item) => $item->lineTotal()), 2);
    }

    /**
     * Lines grouped by merchant account (`products.store_id`), in the order
     * the stores were first added.
     *
     * @return Collection<int, Collection<int, CartItem>>
     */
    public function itemsByStore(): Collection
    {
        $this->loadMissing('items.product');

        return $this->items->sortBy('id')->groupBy(fn (CartItem $item) => (int) $item->product->store_id);
    }

    /**
     * Forget the checkout selections once the cart is empty.
     */
    public function resetAfterEmptied(): void
    {
        $this->forceFill([
            'delivery_type' => null,
            'delivery_date' => null,
            'delivery_slot_id' => null,
            'promo_code_id' => null,
            'gift_message' => null,
        ])->save();
    }
}
