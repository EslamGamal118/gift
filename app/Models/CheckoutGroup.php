<?php

namespace App\Models;

use App\Interfaces\Payable;
use App\Services\Payments\PayableResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One checkout of a (possibly multi-store) cart: the customer pays this once,
 * and it is split into one independent Order per store (`orders.checkout_group_id`).
 * Amounts are the sums of its orders, so each store's order keeps its own
 * delivery fee, discount share and tax.
 *
 * The orders do not exist while the customer pays: `snapshot` freezes the cart
 * (lines, prices, address, delivery, promo per store) when the payment is
 * started, and PaymentService creates the orders from it once the gateway
 * confirms the payment. Groups placed before that change already have their
 * orders and no snapshot.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property string $status pending_payment | paid | cancelled
 * @property string $currency
 * @property string $subtotal
 * @property string $delivery_fee
 * @property string $express_fee
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string $payment_status pending | paid | failed | cancelled
 * @property string|null $payment_method
 * @property array|null $payment_data
 * @property array|null $snapshot
 * @property Carbon|null $paid_at
 */
class CheckoutGroup extends Model implements Payable
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'reference',
        'user_id',
        'status',
        'currency',
        'subtotal',
        'delivery_fee',
        'express_fee',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'payment_data',
        'snapshot',
        'paid_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'express_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_data' => 'array',
        'snapshot' => 'array',
        'paid_at' => 'datetime',
    ];

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
     * One order per store.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id');
    }

    /**
     * Every line of every store order (the gateways' line items).
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Unique human-friendly reference, e.g. CHK-20261003-7K3D9Q.
     */
    public static function generateReference(): string
    {
        do {
            $reference = sprintf('CHK-%s-%s', now()->format('Ymd'), strtoupper(Str::random(6)));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
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
        return PayableResolver::CHECKOUT_GROUP_PREFIX.$this->getKey();
    }

    public function paymentType(): string
    {
        return 'checkout';
    }

    public function paymentReference(): string
    {
        return (string) $this->reference;
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
        return round((float) $this->delivery_fee + (float) $this->express_fee, 2);
    }

    public function paymentDiscountAmount(): float
    {
        return (float) $this->discount_amount;
    }

    /**
     * Every order of the group ships to the same address.
     */
    public function paymentBuyer(): array
    {
        if ($this->snapshot) {
            $order = $this->snapshot['order'];

            return [
                'name' => $order['shipping_name'],
                'phone' => $order['shipping_phone'],
                'email' => $order['shipping_email'],
                'city' => $order['shipping_city'],
                'region' => $order['shipping_city'],
                'address' => $order['shipping_address'],
            ];
        }

        return $this->orders->first()?->paymentBuyer()
            ?? ['name' => null, 'phone' => null, 'email' => null, 'city' => null, 'region' => null, 'address' => null];
    }

    public function paymentCustomer(): ?User
    {
        return $this->user;
    }

    public function paymentLines(): array
    {
        if (! $this->snapshot) {
            return $this->orders->flatMap(fn (Order $order) => $order->paymentLines())->values()->all();
        }

        // Same shape as Order::paymentLines()
        return collect($this->snapshot['stores'])->flatMap(fn (array $store) => $store['items'])->map(fn (array $line) => [
            'reference' => (string) $line['product_id'],
            'name' => (string) $line['product_name'],
            'quantity' => (int) $line['quantity'],
            'unit_price' => round((float) $line['subtotal'] / max(1, (int) $line['quantity']), 2),
            'total' => round((float) $line['subtotal'], 2),
            'image_url' => $line['product_image'] ? asset('storage/'.ltrim($line['product_image'], '/')) : null,
        ])->values()->all();
    }

    public function isPaid(): bool
    {
        return $this->payment_status === Order::PAYMENT_PAID;
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT
            && in_array($this->payment_status, [Order::PAYMENT_PENDING, Order::PAYMENT_FAILED], true);
    }

    public function isPaymentClosed(): bool
    {
        return $this->isCancelled();
    }

    /**
     * Fill the group only; PaymentService marks its orders paid in the same transaction.
     */
    public function markPaid(?string $gateway, ?string $reference): void
    {
        $this->forceFill([
            'status' => self::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'payment_method' => $gateway ?? $this->payment_method,
            'paid_at' => now(),
        ])->mergePaymentData(array_filter([
            'gateway' => $gateway ?? $this->payment_method,
            'reference' => $reference,
            'paid_at' => now()->toIso8601String(),
        ]));
    }

    public function markPaymentFailed(): void
    {
        $this->forceFill(['payment_status' => Order::PAYMENT_FAILED]);
    }
}
