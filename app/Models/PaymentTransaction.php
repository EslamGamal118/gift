<?php

namespace App\Models;

use App\Interfaces\Payable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded interaction with a payment gateway for a store order
 * (`order_id`), a custom order (`custom_order_id`) or a multi-store checkout
 * (`checkout_group_id`).
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $custom_order_id
 * @property int|null $checkout_group_id
 * @property string $gateway alrajhi | tamara | tabby
 * @property string|null $gateway_reference Gateway's own payment / session id
 * @property string $event
 * @property string $status
 * @property string|null $amount
 * @property string|null $currency
 * @property array|null $payload
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'custom_order_id',
        'checkout_group_id',
        'gateway',
        'gateway_reference',
        'event',
        'status',
        'amount',
        'currency',
        'payload',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class);
    }

    public function checkoutGroup(): BelongsTo
    {
        return $this->belongsTo(CheckoutGroup::class);
    }

    /**
     * The store order, custom order or checkout this interaction was for.
     */
    public function payable(): ?Payable
    {
        return match (true) {
            $this->order_id !== null => $this->order,
            $this->checkout_group_id !== null => $this->checkoutGroup,
            default => $this->customOrder,
        };
    }

    public function scopeGateway(Builder $query, string $gateway): Builder
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeReference(Builder $query, string $reference): Builder
    {
        return $query->where('gateway_reference', $reference);
    }
}
