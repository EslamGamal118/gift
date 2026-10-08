<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An online gift (product of a special category) bought by `sender` for a
 * recipient identified by phone. Payment lives on the linked order.
 *
 * @property int $id
 * @property int $order_id
 * @property int $sender_id
 * @property int $store_id
 * @property int|null $product_id
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property string|null $recipient_email
 * @property int|null $recipient_id
 * @property string|null $gift_message
 * @property string $payment_status pending | paid | failed | cancelled | refunded
 * @property string|null $payment_gateway
 * @property Carbon|null $paid_at
 * @property bool $is_claimed
 * @property string $claim_code
 * @property string|null $claim_pin  6 digits texted to the recipient's phone (claim from another account)
 * @property string $sms_status pending | sent | failed | skipped
 * @property Carbon|null $sms_sent_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $opened_at          the recipient opened it in the app
 * @property Carbon|null $popup_dismissed_at  "Not now" on the home screen popup
 * @property string|null $redemption_code  the recipient's QR code, scanned by the store
 * @property Carbon|null $expires_at         end of validity (set when paid)
 * @property Carbon|null $redeemed_at        scanned at the store
 * @property string $whatsapp_status pending | sent | failed | skipped
 * @property Carbon|null $whatsapp_sent_at
 * @property string|null $whatsapp_error
 */
class Gift extends Model
{
    use HasFactory;

    public const WHATSAPP_PENDING = 'pending';

    public const WHATSAPP_SENT = 'sent';

    public const WHATSAPP_FAILED = 'failed';

    public const WHATSAPP_SKIPPED = 'skipped';

    // The claim code SMS (same states as WhatsApp)
    public const SMS_PENDING = 'pending';

    public const SMS_SENT = 'sent';

    public const SMS_FAILED = 'failed';

    public const SMS_SKIPPED = 'skipped';

    // What the recipient can still do with it (the "Online gifts" tabs)
    public const STATUS_ACTIVE = 'active';      // tab "available"

    public const STATUS_REDEEMED = 'redeemed';  // tab "finished"

    public const STATUS_EXPIRED = 'expired';    // tab "finished"

    public const TAB_AVAILABLE = 'available';

    public const TAB_FINISHED = 'finished';

    protected $fillable = [
        'order_id',
        'sender_id',
        'store_id',
        'product_id',
        'recipient_name',
        'recipient_phone',
        'recipient_email',
        'recipient_id',
        'gift_message',
        'payment_status',
        'payment_gateway',
        'paid_at',
        'is_claimed',
        'claim_code',
        'claim_pin',
        'claimed_at',
        'opened_at',
        'popup_dismissed_at',
        'redemption_code',
        'expires_at',
        'redeemed_at',
        'whatsapp_status',
        'whatsapp_sent_at',
        'whatsapp_error',
        'sms_status',
        'sms_sent_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'is_claimed' => 'boolean',
        'claimed_at' => 'datetime',
        'opened_at' => 'datetime',
        'popup_dismissed_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'sms_sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(User::class, 'store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('payment_status', Order::PAYMENT_PAID);
    }

    /**
     * Paid gifts waiting for an account with this (normalized) phone.
     */
    public function scopeUnclaimedFor(Builder $query, string $phone): Builder
    {
        return $query->where('recipient_phone', $phone)->where('is_claimed', false)->paid();
    }

    /**
     * Paid gifts this customer received but has not opened in the app yet.
     */
    public function scopeUnopenedFor(Builder $query, int $recipientId): Builder
    {
        return $query->where('recipient_id', $recipientId)->whereNull('opened_at')->paid();
    }

    /**
     * Still usable: not redeemed, not expired.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('redeemed_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Redeemed or expired.
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('redeemed_at')->orWhere('expires_at', '<=', now()));
    }

    /**
     * active | redeemed | expired
     */
    public function status(): string
    {
        return match (true) {
            $this->redeemed_at !== null => self::STATUS_REDEEMED,
            $this->expires_at !== null && $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function isRedeemable(): bool
    {
        return $this->isPaid() && $this->status() === self::STATUS_ACTIVE;
    }

    /**
     * 6-digit claim code, unique among the gifts still waiting to be claimed.
     */
    public static function generateClaimPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::query()->where('claim_pin', $pin)->where('is_claimed', false)->exists());

        return $pin;
    }

    /**
     * Long random code for the recipient's QR (not guessable, unlike a short claim code).
     */
    public static function generateRedemptionCode(): string
    {
        do {
            $code = Str::upper(Str::random(16));
        } while (self::query()->where('redemption_code', $code)->exists());

        return $code;
    }

    public function isOpened(): bool
    {
        return $this->opened_at !== null;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === Order::PAYMENT_PAID;
    }

    /**
     * Short, unambiguous code (no 0/O/1/I) used in the claim link.
     */
    public static function generateClaimCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
            $code = strtr($code, ['0' => '8', 'O' => 'X', '1' => '7', 'I' => 'Y']);
        } while (self::query()->where('claim_code', $code)->exists());

        return $code;
    }

    /**
     * Universal / deep link sent to the recipient (opens the app or the store page).
     */
    public function claimUrl(): string
    {
        $base = rtrim((string) config('gifts.app_link'), '/');

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['gift' => $this->claim_code]);
    }
}
