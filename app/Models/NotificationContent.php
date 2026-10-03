<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\App;

/**
 * The translatable text of one notification event.
 *
 * Created once per event and pointed at by one {@see AppNotification} per
 * recipient, so a broadcast costs one row here however many inboxes it lands in.
 *
 * @property string $title_key
 * @property string $body_key
 * @property array<string, mixed>|null $title_params
 * @property array<string, mixed>|null $body_params
 * @property string|null $type
 * @property array<string, mixed>|null $data
 */
class NotificationContent extends Model
{
    use HasFactory;

    /**
     * Event categories in use.
     *
     * Left as a plain string column rather than an enum: a new notification
     * category should not need a migration. The constants exist so the call
     * sites stop passing string literals around.
     */
    public const TYPE_ORDER_STATUS = 'order_status';

    public const TYPE_NEW_OFFER = 'new_offer';

    /** Personal shopper: a custom order was assigned to / opened for the shopper. */
    public const TYPE_CUSTOM_ORDER = 'custom_order';

    /** Captains: a dispatched order is waiting for any captain to take it. */
    public const TYPE_DELIVERY_REQUEST = 'delivery_request';

    public const TYPE_WALLET_UPDATE = 'wallet_update';

    /** Online gift: bought (to the store) or received (to the recipient). */
    public const TYPE_GIFT = 'gift';

    /** Admin action on a seller's account: approved, rejected, blocked. */
    public const TYPE_ACCOUNT_STATUS = 'account_status';

    /**
     * The inbox filters (GET /notifications?type=): each groups event types.
     * A type in no list (e.g. account_status, new_offer, a new one) is `system`.
     */
    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_SYSTEM = 'system';

    public const CATEGORIES = [self::CATEGORY_ORDERS, self::CATEGORY_PAYMENTS, self::CATEGORY_SYSTEM];

    /**
     * @var array<string, list<string>>
     */
    public const CATEGORY_TYPES = [
        self::CATEGORY_ORDERS => [self::TYPE_ORDER_STATUS, self::TYPE_CUSTOM_ORDER, self::TYPE_DELIVERY_REQUEST, self::TYPE_GIFT],
        self::CATEGORY_PAYMENTS => [self::TYPE_WALLET_UPDATE],
    ];

    public static function categoryOf(?string $type): string
    {
        foreach (self::CATEGORY_TYPES as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }

        return self::CATEGORY_SYSTEM;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title_key',
        'body_key',
        'title_params',
        'body_params',
        'type',
        'data',
        'sender_type',
        'sender_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'title_params' => 'array',
        'body_params' => 'array',
        'data' => 'array',
    ];

    /**
     * The Client, Provider or Admin that raised this notification.
     *
     * Null for anything the system raised on its own -- a scheduled job, an
     * order status change nobody clicked. Null is a first class value here, so
     * callers must not assume a sender exists.
     */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every delivery of this content, one per recipient.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    |
    | The keys and their parameters live here, so the rendering does too.
    | AppNotification's translated_title / translated_body accessors delegate
    | to these, and NotificationService calls them directly to build a push
    | payload without having to reload a delivery row to reach the text.
    */

    /**
     * The title rendered in `$locale`, or in whatever locale is current.
     */
    public function renderTitle(?string $locale = null): string
    {
        return $this->translate($this->title_key, $this->title_params, $locale);
    }

    /**
     * The body rendered in `$locale`, or in whatever locale is current.
     */
    public function renderBody(?string $locale = null): string
    {
        return $this->translate($this->body_key, $this->body_params, $locale);
    }

    /**
     * Render a translation key.
     *
     * Two things beyond a bare __() call:
     *
     * - A key with no line in the active locale falls back to the app's
     *   fallback locale before it gives up. Without that, one missing Arabic
     *   line shows the shopper the literal string
     *   "notifications.order_updated_body".
     * - __() returns the key unchanged when it misses, and returns an array
     *   when the key points at a nested group, so the result is only trusted
     *   once it is known to be a string.
     *
     * @param  array<string, mixed>|null  $params
     */
    private function translate(?string $key, ?array $params, ?string $locale): string
    {
        if (blank($key)) {
            return '';
        }

        $locale ??= App::getLocale();
        $replace = $this->resolveParams($params ?? [], $locale);
        $fallback = config('app.fallback_locale');

        $line = __($key, $replace, $locale);

        if ($line === $key && $fallback !== null && $fallback !== $locale) {
            $line = __($key, $replace, $fallback);
        }

        return is_string($line) ? $line : $key;
    }

    /**
     * Prepare the stored parameters for interpolation.
     *
     * A parameter whose value is itself translatable cannot be stored as a
     * finished string: writing `['status' => 'تم الشحن']` at send time bakes the
     * sender's language into the row and defeats the entire design. Such a
     * parameter is stored as a nested key instead and resolved here:
     *
     *   ['order_id' => 105, 'status' => ['key' => 'notifications.statuses.shipped']]
     *
     * Plain scalars pass through untouched.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function resolveParams(array $params, string $locale): array
    {
        $resolved = [];

        foreach ($params as $name => $value) {
            if (is_array($value) && isset($value['key']) && is_string($value['key'])) {
                $nested = __($value['key'], [], $locale);
                $resolved[$name] = is_string($nested) ? $nested : $value['key'];

                continue;
            }

            // Arrays and objects have no sensible textual form; keeping them out
            // stops "Array to string conversion" surfacing inside a translation.
            $resolved[$name] = is_scalar($value) ? (string) $value : '';
        }

        return $resolved;
    }

    /**
     * Deliver this content to a set of recipients in one insert.
     *
     * A loop of AppNotification::create() is one INSERT per recipient, which is
     * what turns a broadcast to ten thousand clients into ten thousand round
     * trips. The timestamps are set by hand because insert() bypasses the model
     * events that would otherwise fill them.
     *
     * @param  iterable<int, Model>  $recipients
     * @return int Number of deliveries written.
     */
    public function deliverTo(iterable $recipients): int
    {
        $now = now();
        $rows = [];

        foreach ($recipients as $recipient) {
            $rows[] = [
                'notification_content_id' => $this->getKey(),
                'notifiable_type' => $recipient->getMorphClass(),
                'notifiable_id' => $recipient->getKey(),
                'is_read' => false,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Chunked: a single INSERT with tens of thousands of tuples runs into
        // max_allowed_packet long before it runs into anything else.
        foreach (array_chunk($rows, 500) as $chunk) {
            AppNotification::insert($chunk);
        }

        return count($rows);
    }
}
