<?php

namespace App\Models;

use App\Interfaces\Payable;
use App\Models\Concerns\SearchableByKeyword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A placed order. Address, delivery slot, promo and prices are snapshots taken
 * at placement so later catalogue changes never alter the order.
 *
 * Attribute names (`total_amount`, `tax_amount`, `shipping_cost`, `payment_status`,
 * `payment_data`, `shipping_*`) are shared with the payment gateway services.
 *
 * @property int $id
 * @property int|null $checkout_group_id Checkout (payment) this order was split from
 * @property string $order_number
 * @property int $user_id
 * @property int $store_id
 * @property int|null $address_id
 * @property string|null $shipping_name
 * @property string|null $shipping_phone
 * @property string|null $shipping_email
 * @property string|null $shipping_location_name
 * @property string $shipping_city
 * @property string $shipping_district
 * @property string $shipping_street
 * @property string $shipping_building_number
 * @property string $shipping_address
 * @property string $delivery_type scheduled | instant
 * @property Carbon|null $delivery_date
 * @property int|null $delivery_slot_id
 * @property string|null $delivery_slot_label
 * @property Carbon|null $delivery_window_start
 * @property Carbon|null $delivery_window_end
 * @property int|null $estimated_minutes_min
 * @property int|null $estimated_minutes_max
 * @property string|null $gift_message
 * @property int|null $promo_code_id
 * @property string|null $promo_code
 * @property string $currency
 * @property string $subtotal
 * @property string $delivery_fee
 * @property string $express_fee
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total_amount
 * @property string|null $payment_method alrajhi | tamara | tabby
 * @property string $payment_status pending | paid | failed | cancelled | refunded
 * @property array|null $payment_data
 * @property Carbon|null $paid_at
 * @property string $status
 * @property-read float  $shipping_cost            delivery_fee + express_fee
 */
class Order extends Model implements Payable
{
    use HasFactory, SearchableByKeyword;

    // Order lifecycle (see App\Support\OrderStateMachine for the allowed transitions)
    public const STATUS_PENDING_PAYMENT = 'pending_payment';   // unpaid, invisible to the store

    public const STATUS_PENDING = 'pending';                   // paid, waiting for the store

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PROCESSING = 'processing';             // being prepared

    public const STATUS_READY = 'ready';                       // packed, waiting for a captain

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Who ended the order (orders.cancelled_by / order_status_histories.actor_type).
     */
    public const ACTOR_STORE = 'store';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_CAPTAIN = 'captain';

    public const ACTOR_SYSTEM = 'system';

    /**
     * Statuses the store still has to act on or is working through.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_PROCESSING, self::STATUS_READY, self::STATUS_OUT_FOR_DELIVERY,
    ];

    /**
     * Statuses the order can no longer leave (the customer's "history" tab).
     */
    public const HISTORY_STATUSES = [self::STATUS_DELIVERED, self::STATUS_CANCELLED];

    // Payment lifecycle
    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_CANCELLED = 'cancelled';

    public const PAYMENT_REFUNDED = 'refunded';

    /**
     * Payment statuses a store may see: settled payments only. `refunded` stays
     * visible so a paid-then-cancelled order keeps its place in the store's history.
     */
    public const STORE_VISIBLE_PAYMENT_STATUSES = [self::PAYMENT_PAID, self::PAYMENT_REFUNDED];

    /**
     * Order status => badge shown on the merchant app's order cards.
     */
    public const STORE_BADGES = [
        self::STATUS_PENDING => 'new',
        self::STATUS_ACCEPTED => 'accepted',
        self::STATUS_PROCESSING => 'preparing',
        self::STATUS_READY => 'ready_for_pickup',
        self::STATUS_OUT_FOR_DELIVERY => 'out_for_delivery',
        self::STATUS_DELIVERED => 'completed',
        self::STATUS_CANCELLED => 'cancelled',
    ];

    // Gateways
    public const METHOD_ALRAJHI = 'alrajhi';

    public const METHOD_TAMARA = 'tamara';

    public const METHOD_TABBY = 'tabby';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'checkout_group_id',
        'order_number',
        'user_id',
        'store_id',
        'captain_id',
        'address_id',
        'shipping_name',
        'shipping_phone',
        'shipping_email',
        'shipping_location_name',
        'shipping_city',
        'shipping_district',
        'shipping_street',
        'shipping_building_number',
        'shipping_address',
        'shipping_latitude',
        'shipping_longitude',
        'delivery_type',
        'delivery_date',
        'delivery_slot_id',
        'delivery_slot_label',
        'delivery_window_start',
        'delivery_window_end',
        'estimated_minutes_min',
        'estimated_minutes_max',
        'gift_message',
        'promo_code_id',
        'promo_code',
        'currency',
        'subtotal',
        'delivery_fee',
        'express_fee',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'payment_data',
        'paid_at',
        'status',
        'accepted_at',
        'preparing_at',
        'ready_at',
        'dispatched_at',
        'delivered_at',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'delivery_date' => 'date',
        'delivery_window_start' => 'datetime',
        'delivery_window_end' => 'datetime',
        'estimated_minutes_min' => 'integer',
        'estimated_minutes_max' => 'integer',
        'shipping_latitude' => 'decimal:8',
        'shipping_longitude' => 'decimal:8',
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'express_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_data' => 'array',
        'paid_at' => 'datetime',
        'accepted_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias used by the payment gateway services.
     */
    public function client(): BelongsTo
    {
        return $this->user();
    }

    /**
     * The merchant account the order was placed with.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(User::class, 'store_id');
    }

    public function storeProfile(): HasOneThrough
    {
        return $this->hasOneThrough(StoreProfile::class, User::class, 'id', 'user_id', 'store_id', 'id');
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The customer's review once the order is finished (one per order).
     */
    public function review(): MorphOne
    {
        return $this->morphOne(Review::class, 'reviewable');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * The checkout this order was split from (one payment for all its stores).
     */
    public function checkoutGroup(): BelongsTo
    {
        return $this->belongsTo(CheckoutGroup::class);
    }

    /**
     * The online gift this order pays for (null for regular orders).
     */
    public function gift(): HasOne
    {
        return $this->hasOne(Gift::class);
    }

    /**
     * The delivery captain the order was handed to.
     */
    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Customer search (matchingKeyword scope): order number, product names,
     * store name, and the online gift recipient's name or phone.
     */
    protected function keywordConditions(Builder $query, string $like, ?string $phoneLike, string $viewer): void
    {
        $query->where('orders.order_number', 'like', $like)
            ->orWhereHas('items', fn (Builder $q) => $q->where('product_name', 'like', $like))
            ->orWhereHas('storeProfile', fn (Builder $q) => $q->where('store_profiles.store_name', 'like', $like))
            ->orWhereHas('gift', fn (Builder $q) => $q->where(fn (Builder $g) => $g->where('recipient_name', 'like', $like)
                ->when($phoneLike, fn (Builder $g) => $g->orWhere('recipient_phone', 'like', $phoneLike))));
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * Orders the store should see: only those with a settled payment. Unpaid
     * (`pending`), failed and abandoned payments never reach the store, even if
     * `status` and `payment_status` ever drift apart.
     */
    public function scopeVisibleToStore(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('payment_status'), self::STORE_VISIBLE_PAYMENT_STATUSES)
            ->where($query->qualifyColumn('status'), '!=', self::STATUS_PENDING_PAYMENT);
    }

    /**
     * Strictly `paid` orders (no refunds): what dashboards count and earn from.
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('payment_status'), self::PAYMENT_PAID);
    }

    /**
     * Orders still in progress on the store side.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_PAYMENT)
            ->where('payment_status', self::PAYMENT_PENDING);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Total delivery charge (base fee + instant surcharge). Gateways report this as shipping.
     */
    public function getShippingCostAttribute(): float
    {
        return round((float) $this->delivery_fee + (float) $this->express_fee, 2);
    }

    /**
     * Unique human-friendly number, e.g. GFT-20260915-7K3D9Q.
     */
    public static function generateNumber(): string
    {
        $prefix = config('checkout.orders.number_prefix', 'ORD');

        do {
            $number = sprintf('%s-%s-%s', $prefix, now()->format('Ymd'), strtoupper(Str::random(6)));
        } while (static::query()->where('order_number', $number)->exists());

        return $number;
    }

    public function storeBadge(): string
    {
        return self::STORE_BADGES[$this->status] ?? $this->status;
    }

    public function isInstantDelivery(): bool
    {
        return $this->delivery_type === Cart::DELIVERY_INSTANT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * What a payment for this order must be started on: the whole checkout
     * when the order was split from one (paying a single store's share alone
     * would leave the others unpaid), otherwise the order itself.
     */
    public function payableForPayment(): Payable
    {
        return $this->checkout_group_id ? $this->checkoutGroup : $this;
    }

    public function belongsToStore(int $storeUserId): bool
    {
        return (int) $this->store_id === $storeUserId;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Whether a payment may still be started / retried for this order.
     */
    public function isPayable(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT
            && in_array($this->payment_status, [self::PAYMENT_PENDING, self::PAYMENT_FAILED], true);
    }

    /**
     * Only unpaid orders can be cancelled here; paid ones need a refund flow.
     */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT && ! $this->isPaid();
    }

    /**
     * Merge gateway references into `payment_data` without losing existing keys.
     *
     * @param  array<string, mixed>  $data
     */
    public function mergePaymentData(array $data): static
    {
        $this->payment_data = array_merge($this->payment_data ?? [], $data);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Payable (what the gateways read)
    |--------------------------------------------------------------------------
    */

    public function paymentKey(): string
    {
        return (string) $this->getKey();
    }

    public function paymentType(): string
    {
        return 'order';
    }

    public function paymentReference(): string
    {
        return (string) $this->order_number;
    }

    public function paymentAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function paymentCurrency(): string
    {
        return (string) $this->currency;
    }

    public function paymentTaxAmount(): float
    {
        return (float) $this->tax_amount;
    }

    public function paymentShippingAmount(): float
    {
        return (float) $this->shipping_cost;
    }

    public function paymentDiscountAmount(): float
    {
        return (float) $this->discount_amount;
    }

    public function paymentBuyer(): array
    {
        return [
            'name'    => $this->shipping_name,
            'phone'   => $this->shipping_phone,
            'email'   => $this->shipping_email,
            'city'    => $this->shipping_city,
            'region'  => $this->city?->name_ar ?? $this->shipping_city,
            'address' => $this->shipping_address,
        ];
    }

    public function paymentCustomer(): ?User
    {
        return $this->user;
    }

    public function paymentLines(): array
    {
        return $this->items->map(fn (OrderItem $line) => [
            'reference'  => (string) ($line->product_id ?? $line->id),
            'name'       => (string) $line->product_name,
            'quantity'   => (int) $line->quantity,
            'unit_price' => round((float) $line->subtotal / max(1, (int) $line->quantity), 2),
            'total'      => round((float) $line->subtotal, 2),
            'image_url'  => $line->product_image ? asset('storage/'.ltrim($line->product_image, '/')) : null,
        ])->values()->all();
    }

    public function isPaymentClosed(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Paid: confirmed and handed to the store.
     */
    public function markPaid(?string $gateway, ?string $reference): void
    {
        $this->forceFill([
            'payment_status' => self::PAYMENT_PAID,
            'payment_method' => $gateway ?? $this->payment_method,
            'paid_at'        => now(),
            'status'         => self::STATUS_PENDING,
        ])->mergePaymentData(array_filter([
            'gateway'   => $gateway ?? $this->payment_method,
            'reference' => $reference,
            'paid_at'   => now()->toIso8601String(),
        ]));
    }

    public function markPaymentFailed(): void
    {
        $this->forceFill(['payment_status' => self::PAYMENT_FAILED]);
    }
}
