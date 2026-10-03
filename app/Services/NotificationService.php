<?php

namespace App\Services;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use App\Models\FcmToken;
use App\Models\Gift;
use App\Models\NotificationContent;
use App\Models\Order;
use App\Models\User;
use App\Services\Fcm\FcmClient;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Order status => [title key, body key]. Keys live in lang/{ar,en}/notifications.php.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ORDER_STATUS_KEYS = [
        Order::STATUS_ACCEPTED => ['notifications.order_accepted_title', 'notifications.order_accepted_body'],
        Order::STATUS_PROCESSING => ['notifications.order_processing_title', 'notifications.order_processing_body'],
        Order::STATUS_READY => ['notifications.order_ready_title', 'notifications.order_ready_body'],
        Order::STATUS_OUT_FOR_DELIVERY => ['notifications.order_out_for_delivery_title', 'notifications.order_out_for_delivery_body'],
        Order::STATUS_DELIVERED => ['notifications.order_delivered_title', 'notifications.order_delivered_body'],
        Order::STATUS_CANCELLED => ['notifications.order_cancelled_title', 'notifications.order_cancelled_body'],
    ];

    /**
     * Custom order status set by the shopper => [title key, body key].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CUSTOM_ORDER_STATUS_KEYS = [
        CustomOrder::STATUS_ACCEPTED    => ['notifications.custom_order_accepted_title', 'notifications.custom_order_accepted_body'],
        CustomOrder::STATUS_IN_PROGRESS => ['notifications.custom_order_in_progress_title', 'notifications.custom_order_in_progress_body'],
        CustomOrder::STATUS_WAITING_FOR_PAYMENT => ['notifications.custom_order_waiting_for_payment_title', 'notifications.custom_order_waiting_for_payment_body'],
        CustomOrder::STATUS_COMPLETED   => ['notifications.custom_order_completed_title', 'notifications.custom_order_completed_body'],
        CustomOrder::STATUS_CANCELLED   => ['notifications.custom_order_declined_title', 'notifications.custom_order_declined_body'],
    ];

    public function __construct(protected FcmClient $fcm) {}

    /*
    |--------------------------------------------------------------------------
    | Order events
    |--------------------------------------------------------------------------
    */

    /**
     * Tell the customer their order moved to its current status. The text comes
     * from ORDER_STATUS_KEYS; the data payload carries the new status, its badge
     * and the previous status so the app can update the screen in place.
     */
    public function notifyOrderStatus(Order $order, ?string $reason = null, ?string $previousStatus = null): ?NotificationContent
    {
        $keys = self::ORDER_STATUS_KEYS[$order->status] ?? null;

        if ($keys === null) {
            return null;
        }

        $order->loadMissing(['user', 'storeProfile']);

        $params = [
            'order_number' => $order->order_number,
            'store_name' => $order->storeProfile?->store_name ?? '',
            'reason' => $reason ?? $order->cancellation_reason ?? '',
            'eta_max' => (string) ($order->estimated_minutes_max ?? ''),
        ];

        return $this->send(
            recipients: $order->user,
            titleKey: $keys[0],
            bodyKey: $keys[1],
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_ORDER_STATUS,
            data: $this->orderDeepLink($order) + array_filter([
                'badge' => $order->storeBadge(),
                'previous_status' => $previousStatus,
                'reason' => $order->isCancelled() ? $params['reason'] : null,
            ]),
            sender: $order->store,
        );
    }

    /**
     * Broadcast a dispatched order to every active captain so any of them can
     * take it from the captain app. Pushed to their devices.
     */
    public function notifyCaptainsOrderAvailable(Order $order): ?NotificationContent
    {
        $order->loadMissing('storeProfile');

        $params = [
            'order_number' => $order->order_number,
            'store_name' => $order->storeProfile?->store_name ?? '',
            'district' => $order->shipping_district ?: $order->shipping_city,
        ];

        return $this->send(
            recipients: User::query()->active()->ofType(User::TYPE_CAPTAIN)->get(),
            titleKey: 'notifications.delivery_request_title',
            bodyKey: 'notifications.delivery_request_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_DELIVERY_REQUEST,
            data: $this->orderDeepLink($order, 'captain_delivery_request'),
            sender: $order->store,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Custom order (personal shopper) events
    |--------------------------------------------------------------------------
    */

    /**
     * Tell the chosen shopper a custom order is waiting for their acceptance.
     */
    public function notifyShopperAssigned(CustomOrder $order): ?NotificationContent
    {
        $order->loadMissing(['user', 'shopper'])->loadCount('items');

        if (! $order->shopper) {
            return null;
        }

        $params = $this->customOrderParams($order);

        return $this->send(
            recipients: $order->shopper,
            titleKey: 'notifications.custom_order_assigned_title',
            bodyKey: 'notifications.custom_order_assigned_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_CUSTOM_ORDER,
            data: $this->customOrderDeepLink($order),
            sender: $order->user,
        );
    }

    /**
     * Tell the assigned shopper the customer answered their suggested
     * alternatives (and the order's current status, e.g. accepted again).
     */
    public function notifyShopperAlternativesAnswered(CustomOrder $order, int $approved, int $rejected): ?NotificationContent
    {
        $order->loadMissing(['user', 'shopper'])->loadCount('items');

        if (! $order->shopper) {
            return null;
        }

        $params = $this->customOrderParams($order) + ['approved' => (string) $approved, 'rejected' => (string) $rejected];

        return $this->send(
            recipients: $order->shopper,
            titleKey: 'notifications.custom_order_alternatives_answered_title',
            bodyKey: 'notifications.custom_order_alternatives_answered_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_CUSTOM_ORDER,
            data: $this->customOrderDeepLink($order) + ['approved' => (string) $approved, 'rejected' => (string) $rejected],
            sender: $order->user,
        );
    }

    /**
     * Tell the assigned shopper the customer has paid the invoice.
     */
    public function notifyShopperOrderPaid(CustomOrder $order): ?NotificationContent
    {
        $order->loadMissing(['user', 'shopper'])->loadCount('items');

        if (! $order->shopper) {
            return null;
        }

        $currentLocale = App::getLocale();
        App::setLocale($this->localeOf($order->shopper));
        try {
            $amount = Money::label((float) $order->total_amount, $order->currency);
        } finally {
            App::setLocale($currentLocale);
        }

        $params = $this->customOrderParams($order) + ['amount' => $amount];

        return $this->send(
            recipients: $order->shopper,
            titleKey: 'notifications.custom_order_paid_title',
            bodyKey: 'notifications.custom_order_paid_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_CUSTOM_ORDER,
            data: $this->customOrderDeepLink($order) + ['payment_status' => $order->payment_status],
            sender: $order->user,
        );
    }

    /**
     * Tell the given shoppers a custom order is open for their offers.
     *
     * @param  iterable<int, User>  $shoppers
     */
    public function notifyShoppersBiddingOpened(CustomOrder $order, iterable $shoppers): ?NotificationContent
    {
        $order->loadMissing('user')->loadCount('items');

        $params = $this->customOrderParams($order);

        return $this->send(
            recipients: $shoppers,
            titleKey: 'notifications.custom_order_bidding_title',
            bodyKey: 'notifications.custom_order_bidding_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_CUSTOM_ORDER,
            data: $this->customOrderDeepLink($order, 'custom_order_bidding'),
            sender: $order->user,
        );
    }

    /**
     * Tell the customer the shopper moved their custom order on (accepted,
     * shopping started, purchased) or declined it (with the reason). In-app +
     * push; the data payload carries the new and previous status (and the
     * reason) so the app can refresh the order screen.
     */
    public function notifyCustomOrderStatus(CustomOrder $order, ?string $previousStatus = null): ?NotificationContent
    {
        $keys = self::CUSTOM_ORDER_STATUS_KEYS[$order->status] ?? null;

        if ($keys === null) {
            return null;
        }

        $order->loadMissing(['user', 'shopper']);

        $params = [
            'order_number' => $order->order_number,
            'shopper_name' => $order->shopper?->name ?? '',
            'reason'       => $order->cancellation_reason ?? '',
        ];

        return $this->send(
            recipients: $order->user,
            titleKey: $keys[0],
            bodyKey: $keys[1],
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_CUSTOM_ORDER,
            data: $this->customOrderDeepLink($order) + array_filter([
                'previous_status' => $previousStatus,
                'reason'          => $order->status === CustomOrder::STATUS_CANCELLED ? $order->cancellation_reason : null,
            ]),
            sender: $order->shopper,
        );
    }

    /**
     * Ask the customer to review a product the shopper suggested instead of an
     * unavailable item. The price is formatted in the customer's language.
     */
    public function notifyAlternativeSuggested(CustomOrderAlternative $alternative): ?NotificationContent
    {
        $alternative->loadMissing(['customOrder.user', 'item', 'shopper']);
        $order = $alternative->customOrder;

        if (! $order?->user) {
            return null;
        }

        $currentLocale = App::getLocale();
        App::setLocale($this->localeOf($order->user));
        try {
            $label = Money::label((float) $alternative->price, $alternative->currency);
        } finally {
            App::setLocale($currentLocale);
        }

        $params = [
            'order_number' => $order->order_number,
            'shopper_name' => $alternative->shopper?->name ?? '',
            'item_name'    => $alternative->item?->product_name ?? '',
            'product_name' => $alternative->product_name,
            'price'        => $label,
        ];

        return $this->send(
            recipients: $order->user,
            titleKey: 'notifications.custom_order_alternative_title',
            bodyKey: 'notifications.custom_order_alternative_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_CUSTOM_ORDER,
            data: $this->customOrderDeepLink($order, 'custom_order_alternative') + [
                'alternative_id' => (string) $alternative->id,
                'item_id'        => (string) $alternative->custom_order_item_id,
            ],
            sender: $alternative->shopper,
        );
    }

    /**
     * Tell the store a paid order is waiting for it.
     */
    public function notifyStoreNewOrder(Order $order, bool $push = true): ?NotificationContent
    {
        $order->loadMissing(['store', 'user']);

        $params = [
            'order_number' => $order->order_number,
            'customer_name' => $order->shipping_name ?? $order->user?->name ?? '',
            'total' => number_format((float) $order->total_amount, 2),
            'currency' => $order->currency,
        ];

        return $this->send(
            recipients: $order->store,
            titleKey: 'notifications.new_order_title',
            bodyKey: 'notifications.new_order_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_ORDER_STATUS,
            data: $this->orderDeepLink($order, 'store_order_details'),
            sender: $order->user,
            push: $push,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Online gift events
    |--------------------------------------------------------------------------
    */

    /**
     * Tell the store a paid online gift is waiting to be prepared: gift name,
     * recipient name and phone, and the personal message. Pushed to its devices.
     */
    public function notifyStoreGiftPurchased(Gift $gift): ?NotificationContent
    {
        $gift->loadMissing(['store', 'sender', 'order.items']);

        $params = [
            'gift' => (string) ($gift->order?->items->first()?->product_name ?? ''),
            'recipient_name' => $gift->recipient_name,
            'recipient_phone' => $gift->recipient_phone,
            'message' => (string) $gift->gift_message,
            'order_number' => (string) $gift->order?->order_number,
        ];

        return $this->send(
            recipients: $gift->store,
            titleKey: 'notifications.gift_purchased_title',
            bodyKey: filled($gift->gift_message) ? 'notifications.gift_purchased_body' : 'notifications.gift_purchased_body_no_message',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_GIFT,
            data: $this->orderDeepLink($gift->order, 'store_order_details') + ['gift_id' => (string) $gift->id],
            sender: $gift->sender,
        );
    }

    /**
     * Tell the recipient's account that a gift is waiting for them.
     */
    public function notifyGiftReceived(Gift $gift): ?NotificationContent
    {
        $gift->loadMissing(['recipient', 'sender', 'order.items']);

        if (! $gift->recipient) {
            return null;
        }

        $params = [
            'sender' => $gift->sender?->name ?: __('gifts.someone'),
            'gift' => (string) ($gift->order?->items->first()?->product_name ?? ''),
        ];

        return $this->send(
            recipients: $gift->recipient,
            titleKey: 'notifications.gift_received_title',
            bodyKey: 'notifications.gift_received_body',
            titleParams: $params,
            bodyParams: $params,
            type: NotificationContent::TYPE_GIFT,
            data: ['screen' => 'gift_details', 'gift_id' => (string) $gift->id],
            sender: $gift->sender,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generic API
    |--------------------------------------------------------------------------
    */

    /**
     * Store a notification for the recipients and push it to their devices.
     *
     * @param  iterable<int, User>|User  $recipients
     * @param  array<string, mixed>  $titleParams  Scalars, or ['key' => 'lang.key'] for translatable values
     * @param  array<string, mixed>  $bodyParams
     * @param  array<string, mixed>  $data  Deep-link payload forwarded to the app
     */
    public function send(
        iterable|Model $recipients,
        string $titleKey,
        string $bodyKey,
        array $titleParams = [],
        array $bodyParams = [],
        ?string $type = null,
        array $data = [],
        ?Model $sender = null,
        bool $push = true,
    ): ?NotificationContent {
        $recipients = $this->normaliseRecipients($recipients);

        if ($recipients->isEmpty()) {
            return null;
        }

        $content = DB::transaction(function () use ($recipients, $titleKey, $bodyKey, $titleParams, $bodyParams, $type, $data, $sender): NotificationContent {
            $content = NotificationContent::create([
                'title_key' => $titleKey,
                'body_key' => $bodyKey,
                'title_params' => $titleParams ?: null,
                'body_params' => $bodyParams ?: null,
                'type' => $type,
                'data' => $data ?: null,
                'sender_type' => $sender?->getMorphClass(),
                'sender_id' => $sender?->getKey(),
            ]);

            $content->deliverTo($recipients);

            return $content;
        });

        if ($push) {
            // Queued so the API response never waits on FCM (runs inline on the sync driver).
            SendPushNotification::dispatch($content->getKey(), $recipients->map->getKey()->all())
                ->afterCommit();
        }

        return $content;
    }

    public function sendFromSystem(
        iterable|Model $recipients,
        string $titleKey,
        string $bodyKey,
        array $titleParams = [],
        array $bodyParams = [],
        ?string $type = null,
        array $data = [],
    ): ?NotificationContent {
        return $this->send($recipients, $titleKey, $bodyKey, $titleParams, $bodyParams, $type, $data, null);
    }

    /**
     * Push one content row to every device of every recipient, rendered in each
     * recipient's own language. Called by the SendPushNotification job.
     *
     * @param  Collection<int, User>  $recipients
     */
    public function pushToDevices(NotificationContent $content, Collection $recipients): void
    {
        if (! $this->fcm->isConfigured()) {
            Log::debug('notification.push_skipped', ['reason' => 'fcm_not_configured', 'content_id' => $content->getKey()]);

            return;
        }

        try {
            $stale = [];

            foreach ($recipients->groupBy(fn (User $user) => $this->localeOf($user)) as $locale => $group) {
                $tokens = $this->tokensFor($group);

                if ($tokens === []) {
                    continue;
                }

                $report = $this->fcm->sendToTokens(
                    $tokens,
                    $content->renderTitle((string) $locale),
                    $content->renderBody((string) $locale),
                    $this->pushData($content, (string) $locale),
                );

                $stale = array_merge($stale, $report['invalid']);
            }

            $this->forgetTokens($stale);
        } catch (Throwable $e) {
            Log::error('notification.push_failed', [
                'exception' => $e,
                'content_id' => $content->getKey(),
                'recipients' => $recipients->count(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Inbox helpers
    |--------------------------------------------------------------------------
    */

    public function markAsRead(AppNotification $notification): bool
    {
        return $notification->markAsRead();
    }

    public function markAllAsRead(Model $notifiable): int
    {
        return AppNotification::markAllAsReadFor($notifiable);
    }

    /**
     * One page of a recipient's inbox, newest first, optionally one filter
     * (orders | payments | system).
     */
    public function inbox(Model $notifiable, ?string $category, int $perPage): LengthAwarePaginator
    {
        return AppNotification::query()
            ->for($notifiable)
            ->when($category, fn ($q, string $category) => $q->inCategory($category))
            ->with('content')
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Unread notifications per inbox filter, plus `all`, in one grouped query.
     *
     * @return array<string, int>
     */
    public function unreadCounts(Model $notifiable): array
    {
        $byType = AppNotification::query()
            ->for($notifiable)
            ->unread()
            ->join('notification_contents', 'notification_contents.id', '=', 'app_notifications.notification_content_id')
            ->groupBy('notification_contents.type')
            ->selectRaw('notification_contents.type AS type, COUNT(*) AS total')
            ->toBase()
            ->pluck('total', 'type');

        $counts = ['all' => 0] + array_fill_keys(NotificationContent::CATEGORIES, 0);

        foreach ($byType as $type => $total) {
            $counts[NotificationContent::categoryOf($type === '' ? null : $type)] += (int) $total;
            $counts['all'] += (int) $total;
        }

        return $counts;
    }

    public function unreadCount(Model $notifiable): int
    {
        return AppNotification::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->unread()
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Locale resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The language a recipient reads in: profile `locale`, else the current
     * request locale, else the app default. Unknown values fall back too.
     */
    public function localeOf(User $user): string
    {
        $supported = config('app.supported_locales', ['ar', 'en']);
        $candidates = [$user->preferredLocale(), App::getLocale(), config('app.locale'), config('app.fallback_locale')];

        foreach ($candidates as $locale) {
            if (is_string($locale) && in_array($locale, $supported, true)) {
                return $locale;
            }
        }

        return (string) config('app.fallback_locale', 'en');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, string>
     */
    protected function orderDeepLink(Order $order, string $screen = 'order_details'): array
    {
        return [
            'screen' => $screen,
            'order_id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function customOrderParams(CustomOrder $order): array
    {
        return [
            'order_number' => $order->order_number,
            'customer_name' => $order->user?->name ?? '',
            'items_count' => (string) ($order->items_count ?? $order->items()->count()),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function customOrderDeepLink(CustomOrder $order, string $screen = 'custom_order_details'): array
    {
        return [
            'screen' => $screen,
            'custom_order_id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
        ];
    }

    /**
     * Data payload: keys + params so the app can re-render, plus the rendered
     * text and the deep link.
     *
     * @return array<string, string>
     */
    protected function pushData(NotificationContent $content, string $locale): array
    {
        $payload = [
            'notification_id' => (string) $content->getKey(),
            'type' => (string) ($content->type ?? ''),
            'title_key' => (string) $content->title_key,
            'body_key' => (string) $content->body_key,
            'title_params' => $this->encode($content->title_params),
            'body_params' => $this->encode($content->body_params),
            'locale' => $locale,
            'title' => $content->renderTitle($locale),
            'body' => $content->renderBody($locale),
        ];

        foreach ($content->data ?? [] as $key => $value) {
            if (! array_key_exists($key, $payload) && is_scalar($value)) {
                $payload[$key] = (string) $value;
            }
        }

        return $payload;
    }

    /**
     * @return Collection<int, User>
     */
    protected function normaliseRecipients(iterable|Model $recipients): Collection
    {
        $models = $recipients instanceof Model
            ? collect([$recipients])
            : collect(is_array($recipients) ? $recipients : iterator_to_array($recipients, false));

        return $models
            ->filter(fn ($recipient) => $recipient instanceof User && $recipient->exists)
            ->unique(fn (User $recipient) => $recipient->getKey())
            ->values();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return list<string>
     */
    protected function tokensFor(Collection $recipients): array
    {
        return FcmToken::query()
            ->whereIn('user_id', $recipients->map->getKey()->all())
            ->pluck('token')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function forgetTokens(array $tokens): void
    {
        $tokens = array_values(array_unique(array_map('strval', $tokens)));

        foreach (array_chunk($tokens, 500) as $chunk) {
            FcmToken::query()->whereIn('token', $chunk)->delete();
        }
    }

    protected function encode(?array $value): string
    {
        return json_encode($value ?? new \stdClass, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
