<?php

namespace App\Models;

use App\Interfaces\Payable;
use App\Models\Concerns\SearchableByKeyword;
use App\Services\Payments\PayableResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A "personal shopper" request: items the customer wants bought on their
 * behalf, delivered to a snapshotted address at a chosen time, by a shopper
 * the customer picked directly or who won the bidding.
 *
 *   Step 1 create (draft) ─▶ step 2 choose shopper / bidding (still draft)
 *   ─▶ step 3 confirm with address + delivery time (draft ──▶ pending)
 *
 *   draft ──confirm──▶ pending ──accept──▶ accepted ──start──▶ in_progress
 *     ──purchased (invoice)──▶ waiting_for_payment ──customer pays (gateway)──▶ paid
 *   cancel: draft | pending | accepted | in_progress | waiting_for_alternative | waiting_for_payment ──▶ cancelled
 *
 *   Delivery, moved by the delivery company's webhook (Alshrouq, see canMoveDeliveryTo()):
 *   paid ─▶ order_created ─▶ pending_driver_acceptance ─▶ driver_accepted ─▶ pending_order_preparation
 *     ─▶ arrived_to_pickup ─▶ order_picked_up ─▶ arrived_to_dropoff ─▶ completed (delivered)
 *   any of them ─▶ cancellation_processing ─▶ cancelled (never refunded automatically)
 *
 *   accepted | in_progress ──suggest alternative──▶ waiting_for_alternative, which then
 *   moves on as the status it paused would (accepted ─▶ in_progress, in_progress ─▶ completed)
 *
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int|null $shopper_id
 * @property string|null $assignment_mode direct | bidding
 * @property Carbon|null $bidding_opened_at
 * @property int|null $delivery_address_id  customer's saved address the order is delivered to
 * @property int|null $pickup_address_id  shopper's address where the driver picks the items up
 * @property string|null $delivery_name
 * @property string|null $delivery_phone
 * @property string|null $delivery_location_name
 * @property string|null $delivery_city
 * @property string|null $delivery_district
 * @property string|null $delivery_street
 * @property string|null $delivery_building_number
 * @property string|null $delivery_address
 * @property string|null $delivery_latitude
 * @property string|null $delivery_longitude
 * @property Carbon|null $delivery_at
 * @property Carbon|null $delivery_date
 * @property int|null $delivery_slot_id
 * @property string|null $delivery_slot_label
 * @property Carbon|null $delivery_window_start
 * @property Carbon|null $delivery_window_end
 * @property string|null $notes
 * @property string|null $confirmation_notes
 * @property string $currency
 * @property string|null $budget_min
 * @property string|null $budget_max
 * @property string|null $final_amount  items subtotal: what the shopper actually spent
 * @property string|null $invoice_disk
 * @property string|null $invoice_path
 * @property Carbon|null $invoice_submitted_at
 * @property string|null $delivery_fee
 * @property string|null $shopper_fees
 * @property string|null $tax_amount  VAT on final_amount + delivery_fee
 * @property string|null $total_amount  final_amount + delivery_fee + tax_amount
 * @property string $payment_status  pending | paid | failed | refund_pending | refunded | refund_failed
 * @property string|null $payment_method  alrajhi | tamara | tabby
 * @property array|null $payment_data  gateway references
 * @property Carbon|null $paid_at
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $started_at
 * @property Carbon|null $purchased_at  the shopper bought the items (waiting for payment)
 * @property Carbon|null $completed_at  delivered
 * @property string|null $delivery_reference  the delivery company's order id
 * @property string|null $delivery_status  the delivery company's last raw status
 * @property Carbon|null $delivery_updated_at
 * @property Carbon|null $delivery_dispatched_at  created at the delivery company
 * @property array|null $delivery_data  the delivery company's create response
 * @property array|null $delivery_driver  name, phone, tracking_url, location
 * @property string|null $delivery_error  last failed dispatch
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancellation_reason
 */
class CustomOrder extends Model implements Payable
{
    use HasFactory, SearchableByKeyword;

    // Lifecycle
    public const STATUS_DRAFT = 'draft';               // created, not confirmed yet (shopper may be chosen)

    public const STATUS_PENDING = 'pending';           // confirmed: waiting for the shopper (or for bids)

    public const STATUS_ACCEPTED = 'accepted';         // shopper accepted the request

    public const STATUS_IN_PROGRESS = 'in_progress';   // shopper is buying / on the way

    public const STATUS_WAITING_FOR_ALTERNATIVE = 'waiting_for_alternative';   // shopper suggested an alternative, customer to review

    public const STATUS_WAITING_FOR_PAYMENT = 'waiting_for_payment';   // shopper bought the items, customer to pay the invoice

    public const STATUS_PAID = 'paid';                                 // customer paid: handed to delivery

    // Delivery (Alshrouq), after payment
    public const STATUS_ORDER_CREATED = 'order_created';

    public const STATUS_PENDING_DRIVER_ACCEPTANCE = 'pending_driver_acceptance';

    public const STATUS_DRIVER_ACCEPTED = 'driver_accepted';

    public const STATUS_PENDING_ORDER_PREPARATION = 'pending_order_preparation';

    public const STATUS_ARRIVED_TO_PICKUP = 'arrived_to_pickup';

    public const STATUS_ORDER_PICKED_UP = 'order_picked_up';

    public const STATUS_ARRIVED_TO_DROPOFF = 'arrived_to_dropoff';

    public const STATUS_COMPLETED = 'completed';                       // delivered

    public const STATUS_CANCELLATION_PROCESSING = 'cancellation_processing';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_ACCEPTED,
        self::STATUS_IN_PROGRESS, self::STATUS_WAITING_FOR_ALTERNATIVE, self::STATUS_WAITING_FOR_PAYMENT,
        self::STATUS_PAID, self::STATUS_ORDER_CREATED, self::STATUS_PENDING_DRIVER_ACCEPTANCE,
        self::STATUS_DRIVER_ACCEPTED, self::STATUS_PENDING_ORDER_PREPARATION, self::STATUS_ARRIVED_TO_PICKUP,
        self::STATUS_ORDER_PICKED_UP, self::STATUS_ARRIVED_TO_DROPOFF, self::STATUS_COMPLETED,
        self::STATUS_CANCELLATION_PROCESSING, self::STATUS_CANCELLED,
    ];

    /**
     * From payment to the door, in order. Delivery updates only move forward
     * along it (see canMoveDeliveryTo()).
     */
    public const DELIVERY_FLOW = [
        self::STATUS_PAID, self::STATUS_ORDER_CREATED, self::STATUS_PENDING_DRIVER_ACCEPTANCE,
        self::STATUS_DRIVER_ACCEPTED, self::STATUS_PENDING_ORDER_PREPARATION, self::STATUS_ARRIVED_TO_PICKUP,
        self::STATUS_ORDER_PICKED_UP, self::STATUS_ARRIVED_TO_DROPOFF, self::STATUS_COMPLETED,
    ];

    /**
     * Statuses not finished yet: with the shopper, or paid and being delivered.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_FOR_ALTERNATIVE, self::STATUS_WAITING_FOR_PAYMENT,
        self::STATUS_PAID, self::STATUS_ORDER_CREATED, self::STATUS_PENDING_DRIVER_ACCEPTANCE,
        self::STATUS_DRIVER_ACCEPTED, self::STATUS_PENDING_ORDER_PREPARATION, self::STATUS_ARRIVED_TO_PICKUP,
        self::STATUS_ORDER_PICKED_UP, self::STATUS_ARRIVED_TO_DROPOFF, self::STATUS_CANCELLATION_PROCESSING,
    ];

    /**
     * Final statuses (the customer's "history" tab).
     */
    public const HISTORY_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /**
     * Allowed status transitions by the customer / shopper: from => [to, ...].
     * `waiting_for_alternative` follows the status it paused (see resumeStatus()).
     * Once paid, only the delivery company moves the order (canMoveDeliveryTo()),
     * so it can no longer be cancelled from the app.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT       => [self::STATUS_PENDING, self::STATUS_CANCELLED],
        self::STATUS_PENDING     => [self::STATUS_ACCEPTED, self::STATUS_CANCELLED],
        self::STATUS_ACCEPTED    => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_WAITING_FOR_PAYMENT, self::STATUS_CANCELLED],
        self::STATUS_WAITING_FOR_ALTERNATIVE => [],
        // Paid only by the payment (markPaid()), never by the shopper
        self::STATUS_WAITING_FOR_PAYMENT => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID                      => [],
        self::STATUS_ORDER_CREATED             => [],
        self::STATUS_PENDING_DRIVER_ACCEPTANCE => [],
        self::STATUS_DRIVER_ACCEPTED           => [],
        self::STATUS_PENDING_ORDER_PREPARATION => [],
        self::STATUS_ARRIVED_TO_PICKUP         => [],
        self::STATUS_ORDER_PICKED_UP           => [],
        self::STATUS_ARRIVED_TO_DROPOFF        => [],
        self::STATUS_COMPLETED                 => [],
        self::STATUS_CANCELLATION_PROCESSING   => [],
        self::STATUS_CANCELLED                 => [],
    ];

    // Payment (same values as Order)
    public const PAYMENT_PENDING = Order::PAYMENT_PENDING;

    public const PAYMENT_PAID = Order::PAYMENT_PAID;

    public const PAYMENT_FAILED = Order::PAYMENT_FAILED;

    public const PAYMENT_REFUND_PENDING = 'refund_pending';   // cancelled while paid: refund sent to the gateway

    public const PAYMENT_REFUNDED = Order::PAYMENT_REFUNDED;

    public const PAYMENT_REFUND_FAILED = 'refund_failed';     // the gateway refused: to be handled by hand

    // How the shopper is chosen
    public const MODE_DIRECT = 'direct';

    public const MODE_BIDDING = 'bidding';

    // Who ended the order
    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_SHOPPER = 'shopper';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_DELIVERY = 'delivery';   // the delivery company (never refunded automatically)

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'order_number',
        'user_id',
        'shopper_id',
        'assignment_mode',
        'bidding_opened_at',
        'delivery_address_id',
        'pickup_address_id',
        'delivery_name',
        'delivery_phone',
        'delivery_location_name',
        'delivery_city',
        'delivery_district',
        'delivery_street',
        'delivery_building_number',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'delivery_at',
        'delivery_date',
        'delivery_slot_id',
        'delivery_slot_label',
        'delivery_window_start',
        'delivery_window_end',
        'notes',
        'confirmation_notes',
        'currency',
        'budget_min',
        'budget_max',
        'final_amount',
        'invoice_disk',
        'invoice_path',
        'invoice_submitted_at',
        'delivery_fee',
        'shopper_fees',
        'tax_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'payment_data',
        'paid_at',
        'status',
        'submitted_at',
        'assigned_at',
        'accepted_at',
        'started_at',
        'purchased_at',
        'completed_at',
        'delivery_reference',
        'delivery_status',
        'delivery_updated_at',
        'delivery_dispatched_at',
        'delivery_data',
        'delivery_driver',
        'delivery_error',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'bidding_opened_at'     => 'datetime',
        'delivery_latitude'     => 'decimal:8',
        'delivery_longitude'    => 'decimal:8',
        'delivery_at'           => 'datetime',
        'delivery_date'         => 'date',
        'delivery_window_start' => 'datetime',
        'delivery_window_end'   => 'datetime',
        'budget_min'            => 'decimal:2',
        'budget_max'            => 'decimal:2',
        'final_amount'          => 'decimal:2',
        'invoice_submitted_at'  => 'datetime',
        'delivery_fee'          => 'decimal:2',
        'shopper_fees'          => 'decimal:2',
        'tax_amount'            => 'decimal:2',
        'total_amount'          => 'decimal:2',
        'payment_data'          => 'array',
        'paid_at'               => 'datetime',
        'submitted_at'          => 'datetime',
        'assigned_at'           => 'datetime',
        'accepted_at'           => 'datetime',
        'started_at'            => 'datetime',
        'purchased_at'          => 'datetime',
        'completed_at'          => 'datetime',
        'delivery_updated_at'   => 'datetime',
        'delivery_dispatched_at' => 'datetime',
        'delivery_data'         => 'array',
        'delivery_driver'       => 'array',
        'cancelled_at'          => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The customer who placed the request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The personal shopper account handling it (null until assigned).
     */
    public function shopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shopper_id');
    }

    /**
     * The shopper's public profile (photo, rating, specialties).
     */
    public function shopperProfile(): HasOneThrough
    {
        return $this->hasOneThrough(ShopperProfile::class, User::class, 'id', 'user_id', 'shopper_id', 'id');
    }

    /**
     * Where the order is delivered (عنوان التسليم): one of the customer's saved
     * addresses. The delivery_* columns keep a snapshot of it.
     */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'delivery_address_id');
    }

    /**
     * Where the driver picks the items up (عنوان الاستلام): one of the shopper's
     * addresses, set with the purchase invoice.
     */
    public function pickupAddress(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'pickup_address_id');
    }

    public function deliverySlot(): BelongsTo
    {
        return $this->belongsTo(DeliverySlot::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Products the shopper suggested instead of unavailable items, newest first.
     */
    public function alternatives(): HasMany
    {
        return $this->hasMany(CustomOrderAlternative::class)->latest('id');
    }

    /**
     * Gateway interactions for paying the invoice.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * The customer's review once the order is finished (one per order).
     */
    public function review(): MorphOne
    {
        return $this->morphOne(Review::class, 'reviewable');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(CustomOrderBid::class)->latest('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Keyword search (matchingKeyword scope): order number, notes, item names
     * and descriptions, plus the other party - the shopper's name for the
     * customer; the customer's name, recipient name / phone for the shopper.
     */
    protected function keywordConditions(Builder $query, string $like, ?string $phoneLike, string $viewer): void
    {
        $query->where('custom_orders.order_number', 'like', $like)
            ->orWhere('custom_orders.notes', 'like', $like)
            ->orWhereHas('items', fn (Builder $q) => $q->where(fn (Builder $i) => $i->where('product_name', 'like', $like)
                ->orWhere('description', 'like', $like)));

        if ($viewer === self::KEYWORD_VIEWER_SHOPPER) {
            $query->orWhereHas('user', fn (Builder $q) => $q->where('name', 'like', $like))
                ->orWhere('custom_orders.delivery_name', 'like', $like)
                ->when($phoneLike, fn (Builder $q) => $q->orWhere('custom_orders.delivery_phone', 'like', $phoneLike));

            return;
        }

        $query->orWhereHas('shopper', fn (Builder $q) => $q->where('name', 'like', $like));
    }

    public function scopeForCustomer(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForShopper(Builder $query, int $shopperId): Builder
    {
        return $query->where('shopper_id', $shopperId);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Orders open for bids: bidding mode, still pending, nobody assigned.
     */
    public function scopeOpenForBidding(Builder $query): Builder
    {
        return $query
            ->where('assignment_mode', self::MODE_BIDDING)
            ->where('status', self::STATUS_PENDING)
            ->whereNull('shopper_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle helpers
    |--------------------------------------------------------------------------
    */

    public static function generateNumber(): string
    {
        $prefix = config('custom_orders.number_prefix', 'CO');

        do {
            $number = sprintf('%s-%s-%s', $prefix, now()->format('Ymd'), strtoupper(Str::random(6)));
        } while (static::query()->where('order_number', $number)->exists());

        return $number;
    }

    public function canTransitionTo(string $status): bool
    {
        $from = $this->isWaitingForAlternative() ? $this->resumeStatus() : $this->status;

        return in_array($status, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Whether a delivery update may move the order to `$status`: only once paid
     * and until delivered or cancelled; forward along DELIVERY_FLOW (updates may
     * skip steps, and a late one never moves the order back), except back to
     * waiting for a driver when the driver drops it before pickup. A cancellation
     * (or one being processed) is accepted at any point; while one is processed
     * the delivery may still resume.
     */
    public function canMoveDeliveryTo(string $status): bool
    {
        if (! $this->isPaid() || $status === $this->status || $status === self::STATUS_PAID) {
            return false;
        }

        $current = $this->status === self::STATUS_CANCELLATION_PROCESSING
            ? 0
            : array_search($this->status, self::DELIVERY_FLOW, true);

        if ($current === false || $this->status === self::STATUS_COMPLETED) {
            return false;
        }

        if (in_array($status, [self::STATUS_CANCELLATION_PROCESSING, self::STATUS_CANCELLED], true)) {
            return true;
        }

        $target = array_search($status, self::DELIVERY_FLOW, true);

        if ($target === false) {
            return false;
        }

        return $target > $current
            || ($status === self::STATUS_PENDING_DRIVER_ACCEPTANCE
                && $current < array_search(self::STATUS_ORDER_PICKED_UP, self::DELIVERY_FLOW, true));
    }

    public function isWaitingForAlternative(): bool
    {
        return $this->status === self::STATUS_WAITING_FOR_ALTERNATIVE;
    }

    /**
     * The status an order waiting for an alternative paused: in progress once
     * shopping started, accepted before.
     */
    public function resumeStatus(): string
    {
        return $this->started_at ? self::STATUS_IN_PROGRESS : self::STATUS_ACCEPTED;
    }

    /**
     * Delivery columns for one of the customer's saved addresses: its id plus
     * a snapshot (the saved address may change or be deleted later).
     *
     * @return array<string, mixed>
     */
    public static function deliveryAddressAttributes(UserAddress $address, User $customer): array
    {
        return [
            'delivery_address_id'      => $address->id,
            'delivery_name'            => $customer->name,
            'delivery_phone'           => $address->phone ?: $customer->phone,
            'delivery_location_name'   => $address->location_name,
            'delivery_city'            => $address->city,
            'delivery_district'        => $address->district,
            'delivery_street'          => $address->street,
            'delivery_building_number' => $address->building_number,
            'delivery_address'         => $address->toLine(),
            'delivery_latitude'        => $address->latitude,
            'delivery_longitude'       => $address->longitude,
        ];
    }

    public function invoiceUrl(): ?string
    {
        return $this->invoice_path ? Storage::disk($this->invoice_disk ?: 'public')->url($this->invoice_path) : null;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }

    public function hasShopper(): bool
    {
        return $this->shopper_id !== null;
    }

    public function isBidding(): bool
    {
        return $this->assignment_mode === self::MODE_BIDDING;
    }

    public function isOpenForBidding(): bool
    {
        return $this->isBidding() && $this->isPending() && ! $this->hasShopper();
    }

    /**
     * A shopper can still be chosen (directly or by opening bidding) while the
     * order is a draft, or pending without an accepted shopper.
     */
    public function isAssignable(): bool
    {
        return $this->isDraft() || ($this->isPending() && ! $this->hasShopper());
    }

    /**
     * Step 2 is done: a shopper was picked, or the order is set for bidding.
     */
    public function hasShopperChoice(): bool
    {
        return $this->hasShopper() || $this->isBidding();
    }

    /**
     * Step 3 (confirm) is possible: still a draft and the shopper choice is made.
     */
    public function isConfirmable(): bool
    {
        return $this->isDraft() && $this->hasShopperChoice();
    }

    public function hasDeliveryAddress(): bool
    {
        return $this->delivery_city !== null && $this->delivery_address !== null;
    }

    public function hasDeliverySlot(): bool
    {
        return $this->delivery_slot_id !== null;
    }

    public function hasDeliveryCoordinates(): bool
    {
        return $this->delivery_latitude !== null && $this->delivery_longitude !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Payable: the customer pays the invoice (items + shopper fees + delivery + VAT)
    |--------------------------------------------------------------------------
    */

    public function paymentKey(): string
    {
        return PayableResolver::CUSTOM_ORDER_PREFIX.$this->getKey();
    }

    public function paymentType(): string
    {
        return 'custom_order';
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
        return (float) $this->delivery_fee;
    }

    public function paymentDiscountAmount(): float
    {
        return 0.0;
    }

    public function paymentBuyer(): array
    {
        $customer = $this->paymentCustomer();

        return [
            'name'    => $this->delivery_name ?: $customer?->name,
            'phone'   => $this->delivery_phone ?: $customer?->phone,
            'email'   => $customer?->email,
            'city'    => $this->delivery_city,
            'region'  => $this->delivery_district ?: $this->delivery_city,
            'address' => $this->delivery_address,
        ];
    }

    public function paymentCustomer(): ?User
    {
        return $this->user;
    }

    /**
     * The purchased items (those bought, at the price paid) and the shopper's fees.
     */
    public function paymentLines(): array
    {
        $lines = $this->items
            ->filter(fn (CustomOrderItem $item) => (float) $item->unit_price > 0)
            ->map(fn (CustomOrderItem $item) => [
                'reference'  => 'COI-'.$item->id,
                'name'       => (string) $item->product_name,
                'quantity'   => (int) $item->quantity,
                'unit_price' => round((float) $item->unit_price, 2),
                'total'      => (float) $item->totalPrice(),
                'image_url'  => null,
            ])->values();

        if ((float) $this->shopper_fees > 0) {
            $lines->push([
                'reference'  => 'COF-'.$this->id,
                'name'       => (string) __('custom_orders.shopper_fees_line'),
                'quantity'   => 1,
                'unit_price' => round((float) $this->shopper_fees, 2),
                'total'      => round((float) $this->shopper_fees, 2),
                'image_url'  => null,
            ]);
        }

        return $lines->all();
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Purchased and invoiced (priced), not paid yet.
     */
    public function isPayable(): bool
    {
        return $this->status === self::STATUS_WAITING_FOR_PAYMENT
            && $this->invoice_submitted_at !== null
            && (float) $this->total_amount > 0
            && in_array($this->payment_status, [self::PAYMENT_PENDING, self::PAYMENT_FAILED], true);
    }

    public function isPaymentClosed(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Paid: an order waiting for its payment becomes `paid`, ready for delivery
     * (completed once delivered). A payment landing on a cancelled order only
     * records it, to be refunded.
     */
    public function markPaid(?string $gateway, ?string $reference): void
    {
        if ($this->status === self::STATUS_WAITING_FOR_PAYMENT) {
            $this->forceFill(['status' => self::STATUS_PAID]);
        }

        $this->forceFill([
            'payment_status' => self::PAYMENT_PAID,
            'payment_method' => $gateway ?? $this->payment_method,
            'paid_at'        => now(),
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function mergePaymentData(array $data): static
    {
        $this->payment_data = array_merge($this->payment_data ?? [], $data);

        return $this;
    }
}
