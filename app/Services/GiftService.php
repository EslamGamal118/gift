<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Exceptions\GiftClaimException;
use App\Exceptions\GiftRedemptionException;
use App\Jobs\SendGiftSms;
use App\Jobs\SendGiftWhatsApp;
use App\Models\Addon;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Support\City;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Online gifts: products of a special category bought for someone else.
 *
 * Flow
 *   0. details()             gift screen: store, product, greeting cards and the
 *                            financial summary of the current selection (quote())
 *   1. checkout()            order (pending_payment, one line, no delivery) + gift row, stock reserved
 *                            (recipient_id set when a customer has the recipient's phone)
 *   2. gateway page          reuses the order payment flow (Al Rajhi / Tamara / Tabby)
 *   3. handlePaid()          called by PaymentService once per order when payment is confirmed:
 *                            gift -> paid, recipient account attached if it exists,
 *                            WhatsApp to the recipient, notification to the store
 *   4. claimPendingFor()     a new customer account claims the paid gifts sent to its phone
 *   5. claimWithCode()       an account with another phone claims it with the 6-digit code
 *                            texted (SMS + WhatsApp) to the recipient's phone
 */
class GiftService
{
    /**
     * What a received gift's details / card need.
     */
    public const DETAIL_RELATIONS = ['order.items', 'store.storeProfile.mainBranch', 'sender:id,name,avatar'];

    public function __construct(
        protected CheckoutService $checkout,
        protected PromoCodeService $promos,
        protected NotificationService $notifications,
        protected EvolutionApiClient $whatsapp,
        protected ForJawalyProvider $sms,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the gift details / payment screen shows for a selection:
     * the add-ons the customer can attach (greeting cards, ...) and the price
     * breakdown. An unusable promo code does not fail the screen: it is left
     * out of the totals and explained in `promo_error`.
     *
     * @param  array<int, int>  $addonIds
     * @return array{
     *     product: Product, quantity: int, addons: Collection<int, Addon>, selected_addon_ids: array<int, int>,
     *     promo: ?PromoCode, promo_error: ?string, totals: array<string, mixed>, available: bool
     * }
     */
    public function details(User $user, Product $product, int $quantity = 1, array $addonIds = [], ?string $promoCode = null): array
    {
        $product->loadMissing(['category', 'storeProfile.category']);

        $addons = $product->availableAddons()->orderBy('price')->orderBy('name')->get();
        $selected = $addons->whereIn('id', $addonIds)->values();

        $promo = null;
        $promoError = null;

        if (filled($promoCode)) {
            try {
                $promo = $this->promos->resolve($promoCode, $user, $product->store_id, $this->subtotal($product, $quantity, $selected));
            } catch (CheckoutException $e) {
                $promoError = __($e->getMessageKey(), $e->getReplace());
            }
        }

        $available = $product->stock_quantity >= $quantity
            && $selected->every(fn (Addon $addon) => $addon->stock_quantity >= $quantity);

        return [
            'product' => $product,
            'quantity' => $quantity,
            'addons' => $addons,
            'selected_addon_ids' => $selected->pluck('id')->all(),
            'promo' => $promo,
            'promo_error' => $promoError,
            'totals' => $this->quote($product, $quantity, $selected, $promo),
            'available' => $available,
        ];
    }

    /**
     * Price breakdown of a gift. Online gifts are delivered digitally, so there
     * is no delivery fee; tax follows CheckoutService::totals().
     *
     * @param  Collection<int, Addon>  $addons
     * @return array<string, mixed>
     */
    public function quote(Product $product, int $quantity, Collection $addons, ?PromoCode $promo): array
    {
        $itemsTotal = round((float) $product->price * $quantity, 2);
        $addonsTotal = round($addons->sum(fn (Addon $addon) => (float) $addon->price * $quantity), 2);
        $subtotal = round($itemsTotal + $addonsTotal, 2);

        return [
            'items_total' => $itemsTotal,
            'addons_total' => $addonsTotal,
        ] + $this->checkout->totals($subtotal, $this->promos->discount($promo, $subtotal), 0, 0);
    }

    /**
     * Freeze the gift into an unpaid order. The caller then starts the payment.
     * Priced by quote(), i.e. exactly what details() showed.
     *
     * @param  array{product_id: int, quantity?: int, addon_ids?: array<int, int>, promo_code?: ?string, recipient_name: string, recipient_phone: string, recipient_email?: ?string, gift_message?: ?string, gateway: string}  $data
     *
     * @throws CheckoutException
     */
    public function checkout(User $sender, array $data): Gift
    {
        return DB::transaction(function () use ($sender, $data) {
            $quantity = max(1, (int) ($data['quantity'] ?? 1));

            /** @var Product|null $product */
            $product = $this->giftableQuery()->lockForUpdate()->find($data['product_id']);

            if (! $product) {
                throw CheckoutException::productUnavailable('');
            }

            if ($product->stock_quantity < $quantity) {
                throw CheckoutException::insufficientStock($product->name, (int) $product->stock_quantity);
            }

            $addons = $product->availableAddons()->whereKey($data['addon_ids'] ?? [])->lockForUpdate()->get();

            foreach ($addons as $addon) {
                if ($addon->stock_quantity < $quantity) {
                    throw CheckoutException::insufficientStock($addon->name, (int) $addon->stock_quantity);
                }
            }

            $promo = filled($data['promo_code'] ?? null)
                ? $this->lockedPromo((string) $data['promo_code'], $sender, $product, $this->subtotal($product, $quantity, $addons))
                : null;

            $totals = $this->quote($product, $quantity, $addons, $promo);
            $message = filled($data['gift_message'] ?? null) ? trim((string) $data['gift_message']) : null;

            $order = Order::query()->create([
                'order_number' => Order::generateNumber(),
                'user_id' => $sender->id,
                'store_id' => $product->store_id,
                'gift_message' => $message,
                'promo_code_id' => $promo?->id,
                'promo_code' => $promo?->code,
                'currency' => $totals['currency'],
                'subtotal' => $totals['subtotal'],
                'delivery_fee' => 0,
                'express_fee' => 0,
                'discount_amount' => $totals['discount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'payment_method' => $data['gateway'],
                'payment_status' => Order::PAYMENT_PENDING,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'delivery_type' => 'instant',
            ] + $this->orderContact($sender, $data['recipient_name']));

            $line = $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_image' => $product->image,
                'unit_price' => $product->price,
                'quantity' => $quantity,
                'addons_total' => $totals['addons_total'],
                'subtotal' => $totals['subtotal'],
            ]);

            foreach ($addons as $addon) {
                $line->addons()->create([
                    'addon_id' => $addon->id,
                    'name' => $addon->name,
                    'unit_price' => $addon->price,
                    'quantity' => $quantity,
                    'subtotal' => round((float) $addon->price * $quantity, 2),
                ]);
                $addon->decrement('stock_quantity', $quantity);
            }

            $product->decrement('stock_quantity', $quantity);
            $promo?->increment('used_count');

            return Gift::query()->create([
                'order_id' => $order->id,
                'sender_id' => $sender->id,
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'recipient_name' => trim($data['recipient_name']),
                'recipient_phone' => $data['recipient_phone'],
                'recipient_email' => $data['recipient_email'] ?? null,
                // Linked now so the sender sees "has an account"; claimed only once paid
                'recipient_id' => $this->recipientAccount($data['recipient_phone'])?->id,
                'gift_message' => $message,
                'payment_status' => Order::PAYMENT_PENDING,
                'payment_gateway' => $data['gateway'],
                'claim_code' => Gift::generateClaimCode(),
            ])->setRelation('order', $order);
        });
    }

    /**
     * Visible, current, in-stock products of an active special category.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Product>
     */
    public function giftableQuery()
    {
        return Product::query()
            ->visible()
            ->notExpired()
            ->whereHas('category', fn ($q) => $q->active()->special());
    }

    /*
    |--------------------------------------------------------------------------
    | Payment outcome (called by PaymentService)
    |--------------------------------------------------------------------------
    */

    /**
     * The gift's order was just paid (PaymentService guarantees once per order).
     * Returns false when the order is not a gift.
     */
    public function handlePaid(Order $order): bool
    {
        $gift = DB::transaction(function () use ($order) {
            /** @var Gift|null $gift */
            $gift = Gift::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if (! $gift) {
                return null;
            }

            if (! $gift->isPaid()) {
                $paidAt = $order->paid_at ?? now();

                $gift->forceFill([
                    'payment_status' => Order::PAYMENT_PAID,
                    'payment_gateway' => $order->payment_method ?? $gift->payment_gateway,
                    'paid_at' => $paidAt,
                    // The recipient's QR and how long the store accepts it
                    'redemption_code' => $gift->redemption_code ?? Gift::generateRedemptionCode(),
                    'expires_at' => $paidAt->copy()->addDays($this->validityDays($gift))->endOfDay(),
                ])->save();
            }

            $this->attachExistingRecipient($gift);

            // Nobody has it yet: the recipient's phone gets a code to claim it from any account
            if (! $gift->is_claimed && ! $gift->claim_pin) {
                $gift->forceFill(['claim_pin' => Gift::generateClaimPin()])->save();
            }

            return $gift;
        });

        if (! $gift) {
            return false;
        }

        $gift->setRelation('order', $order);

        // Each step is independent: a failing channel must not block the others,
        // nor the gateway's webhook (the job runs inline on the sync queue driver).
        $this->safely(function () use ($gift) {
            SendGiftWhatsApp::dispatch($gift->id)->afterCommit();
        }, 'WhatsApp dispatch', $gift);

        if (! $gift->is_claimed) {
            $this->safely(function () use ($gift) {
                SendGiftSms::dispatch($gift->id)->afterCommit();
            }, 'SMS dispatch', $gift);
        } else {
            $gift->forceFill(['sms_status' => Gift::SMS_SKIPPED])->save();   // already in the recipient's account
        }
        $this->safely(fn () => $this->notifications->notifyStoreGiftPurchased($gift), 'store notification', $gift);

        if ($gift->recipient_id) {
            $this->safely(fn () => $this->notifications->notifyGiftReceived($gift), 'recipient notification', $gift);
        }

        return true;
    }

    /**
     * Mirror a failed / cancelled / refunded payment of the order onto its gift.
     */
    public function syncPaymentStatus(Order $order): void
    {
        Gift::query()
            ->where('order_id', $order->id)
            ->where('payment_status', '!=', Order::PAYMENT_PAID)
            ->update([
                'payment_status' => $order->payment_status,
                'payment_gateway' => $order->payment_method,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Claiming
    |--------------------------------------------------------------------------
    */

    /**
     * Attach the gift to the customer account registered with the recipient's
     * phone, if there is one. Call inside a transaction holding the gift lock.
     */
    public function attachExistingRecipient(Gift $gift): void
    {
        if ($gift->is_claimed) {
            return;
        }

        // Looked up again: the account may have been created or deleted since checkout
        $recipient = $this->recipientAccount($gift->recipient_phone);

        $gift->forceFill($recipient
            ? ['recipient_id' => $recipient->id, 'is_claimed' => true, 'claimed_at' => now()]
            : ['recipient_id' => null])->save();
    }

    /**
     * The customer account registered with this normalized phone. Other roles
     * (store, captain, shopper) can share the number but never receive gifts.
     */
    public function recipientAccount(string $phone): ?User
    {
        return User::query()->forPhoneAndRole($phone, User::TYPE_CUSTOMER)->first();
    }

    /**
     * Attach every paid, unclaimed gift sent to this customer's phone. Called
     * when a customer account is created; returns how many were claimed.
     */
    public function claimPendingFor(User $user): int
    {
        if ($user->user_type !== User::TYPE_CUSTOMER || blank($user->phone)) {
            return 0;
        }

        $claimed = DB::transaction(function () use ($user) {
            $gifts = Gift::query()->unclaimedFor($user->phone)->lockForUpdate()->get();

            foreach ($gifts as $gift) {
                $gift->forceFill(['recipient_id' => $user->id, 'is_claimed' => true, 'claimed_at' => now()])->save();
            }

            return $gifts;
        });

        foreach ($claimed as $gift) {
            $this->safely(fn () => $this->notifications->notifyGiftReceived($gift), 'recipient notification', $gift);
        }

        return $claimed->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Opening (home screen popup)
    |--------------------------------------------------------------------------
    */

    /**
     * The "You received a new gift" popup of a customer's home screen: the
     * newest received gift they have neither opened nor put off ("Not now"),
     * and how many are still unopened in total.
     *
     * @return array{gift: ?Gift, unopened_count: int}
     */
    public function incomingFor(?User $user): array
    {
        if (! $user || $user->user_type !== User::TYPE_CUSTOMER) {
            return ['gift' => null, 'unopened_count' => 0];
        }

        $unopened = Gift::query()->unopenedFor($user->id);

        return [
            'gift' => (clone $unopened)
                ->whereNull('popup_dismissed_at')
                ->with(['sender:id,name,avatar', 'order.items', 'store.storeProfile'])
                ->latest('paid_at')
                ->latest('id')
                ->first(),
            'unopened_count' => $unopened->count(),
        ];
    }

    /**
     * "Open my gift": the recipient sees it; idempotent.
     */
    public function open(User $recipient, int $giftId): Gift
    {
        $gift = $this->receivedBy($recipient, $giftId);

        if (! $gift->isOpened()) {
            $gift->forceFill(['opened_at' => now()])->save();
        }

        return $gift->load(['order.items', 'store.storeProfile', 'sender:id,name,avatar']);
    }

    /**
     * "Not now": no popup for this gift any more; it stays unopened in the received list.
     */
    public function dismissPopup(User $recipient, int $giftId): Gift
    {
        $gift = $this->receivedBy($recipient, $giftId);

        if (! $gift->popup_dismissed_at) {
            $gift->forceFill(['popup_dismissed_at' => now()])->save();
        }

        return $gift;
    }

    /**
     * A paid gift received by this customer (404 otherwise).
     */
    protected function receivedBy(User $recipient, int $giftId): Gift
    {
        return $recipient->receivedGifts()->paid()->findOrFail($giftId);
    }

    /*
    |--------------------------------------------------------------------------
    | Received gifts screen & redemption at the store
    |--------------------------------------------------------------------------
    */

    /**
     * The "Online gifts" screen: one tab's page (available = usable,
     * finished = redeemed or expired; null = both) and each tab's count.
     *
     * @return array{gifts: LengthAwarePaginator, counts: array<string, int>}
     */
    public function received(User $recipient, ?string $tab, int $perPage): array
    {
        $base = fn () => $recipient->receivedGifts()->paid();

        return [
            'gifts' => $base()
                ->when($tab === Gift::TAB_AVAILABLE, fn ($q) => $q->available())
                ->when($tab === Gift::TAB_FINISHED, fn ($q) => $q->finished())
                ->with(self::DETAIL_RELATIONS)
                // Available: the soonest to expire first; finished / all: newest first
                ->when($tab === Gift::TAB_AVAILABLE, fn ($q) => $q->orderBy('expires_at'), fn ($q) => $q->latest('paid_at'))
                ->latest('id')
                ->paginate($perPage),
            'counts' => [
                Gift::TAB_AVAILABLE => $base()->available()->count(),
                Gift::TAB_FINISHED => $base()->finished()->count(),
            ],
        ];
    }

    /**
     * One received gift with what its details screen shows.
     */
    public function receivedDetails(User $recipient, int $giftId): Gift
    {
        return $this->receivedBy($recipient, $giftId)->load(self::DETAIL_RELATIONS);
    }

    /**
     * The store scans the recipient's QR: the gift must be this store's,
     * paid, not redeemed yet and not expired. Locked so a code is used once.
     *
     * @throws GiftRedemptionException
     */
    public function redeem(User $store, string $code): Gift
    {
        return DB::transaction(function () use ($store, $code) {
            /** @var Gift|null $gift */
            $gift = Gift::query()
                ->where('redemption_code', strtoupper(trim($code)))
                ->where('store_id', $store->id)
                ->paid()
                ->lockForUpdate()
                ->first();

            if (! $gift) {
                throw GiftRedemptionException::notFound();
            }

            match ($gift->status()) {
                Gift::STATUS_REDEEMED => throw GiftRedemptionException::alreadyRedeemed($gift),
                Gift::STATUS_EXPIRED => throw GiftRedemptionException::expired($gift),
                default => null,
            };

            $gift->forceFill(['redeemed_at' => now()])->save();

            return $gift->load(['order.items', 'sender:id,name']);
        });
    }

    /**
     * Days the store accepts the gift: the product's own validity, else the default.
     */
    protected function validityDays(Gift $gift): int
    {
        $days = $gift->product_id
            ? Product::query()->whereKey($gift->product_id)->value('gift_validity_days')
            : null;

        return (int) ($days ?: config('gifts.validity_days', 90));
    }

    /**
     * "Claim a gift": the 6-digit code texted to the recipient's phone attaches
     * the gift to this account, whatever its phone. Claiming one's own gift
     * again is a no-op; the sender cannot claim the gift they sent.
     *
     * @throws GiftClaimException
     */
    public function claimWithCode(User $customer, string $code): Gift
    {
        [$gift, $justClaimed] = DB::transaction(function () use ($customer, $code) {
            /** @var Gift|null $gift */
            $gift = Gift::query()
                ->where('claim_pin', $code)
                ->paid()
                ->orderBy('is_claimed')        // a waiting gift wins over an old claimed one with the same code
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $gift) {
                throw GiftClaimException::invalidCode();
            }

            if ($gift->is_claimed) {
                return $gift->recipient_id === $customer->id ? [$gift, false] : throw GiftClaimException::alreadyClaimed();
            }

            if ($gift->sender_id === $customer->id) {
                throw GiftClaimException::ownGift();
            }

            if ($gift->status() === Gift::STATUS_EXPIRED) {
                throw GiftClaimException::expired($gift);
            }

            $gift->forceFill(['recipient_id' => $customer->id, 'is_claimed' => true, 'claimed_at' => now()])->save();

            return [$gift, true];
        });

        if ($justClaimed) {
            $this->safely(fn () => $this->notifications->notifyGiftReceived($gift), 'recipient notification', $gift);
        }

        return $gift->load(self::DETAIL_RELATIONS);
    }

    /*
    |--------------------------------------------------------------------------
    | SMS (claim code)
    |--------------------------------------------------------------------------
    */

    /**
     * Text the claim code to the recipient's phone. Like OTP codes, no real SMS
     * leaves the OTP testing environments (local / staging / testing): the code
     * is logged and the gift marked `skipped`, as when SMS is not configured.
     * Throws when sending fails so the job retries.
     */
    public function sendSms(Gift $gift): void
    {
        if (app()->environment(config('otp.testing_environments', [])) || ! $this->sms->isConfigured()) {
            Log::info('[Gift] Claim code SMS not sent (testing environment or SMS not configured)', [
                'gift_id' => $gift->id,
                'phone' => PhoneNumber::mask($gift->recipient_phone),
                'code' => $gift->claim_pin,
            ]);
            $gift->forceFill(['sms_status' => Gift::SMS_SKIPPED])->save();

            return;
        }

        if (! $this->sms->send($gift->recipient_phone, $this->smsMessage($gift))) {
            throw new \RuntimeException('Gift claim code SMS was not accepted by the provider');
        }

        $gift->forceFill(['sms_status' => Gift::SMS_SENT, 'sms_sent_at' => now()])->save();
    }

    /**
     * Short SMS (Arabic): who sent it and the code to type in "Claim a gift".
     */
    public function smsMessage(Gift $gift): string
    {
        $gift->loadMissing('sender');

        return __('gifts.sms', [
            'app' => config('app.name'),
            'sender' => $gift->sender?->name ?: __('gifts.someone', [], 'ar'),
            'code' => $gift->claim_pin,
        ], 'ar');
    }

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    /**
     * Send the gift message to the recipient. Marks the gift `skipped` when
     * WhatsApp is not configured; throws on API errors so the job retries.
     */
    public function sendWhatsApp(Gift $gift): void
    {
        if (! $this->whatsapp->isConfigured()) {
            $gift->forceFill(['whatsapp_status' => Gift::WHATSAPP_SKIPPED])->save();

            return;
        }

        try {
            $this->whatsapp->sendText($gift->recipient_phone, $this->whatsAppMessage($gift));
        } catch (\Throwable $e) {
            $gift->forceFill(['whatsapp_error' => mb_substr($e->getMessage(), 0, 250)])->save();

            throw $e;
        }

        $gift->forceFill([
            'whatsapp_status' => Gift::WHATSAPP_SENT,
            'whatsapp_sent_at' => now(),
            'whatsapp_error' => null,
        ])->save();
    }

    /**
     * WhatsApp text (Arabic): sender, gift, personal message and the app link.
     */
    public function whatsAppMessage(Gift $gift): string
    {
        $gift->loadMissing(['sender', 'order.items']);

        $params = [
            'recipient' => $gift->recipient_name,
            'sender' => $gift->sender?->name ?: __('gifts.someone', [], 'ar'),
            'gift' => $this->giftName($gift),
            'link' => $gift->claimUrl(),
        ];

        $lines = [__('gifts.whatsapp.greeting', $params, 'ar'), __('gifts.whatsapp.body', $params, 'ar')];

        if (filled($gift->gift_message)) {
            $lines[] = __('gifts.whatsapp.message', ['message' => $gift->gift_message], 'ar');
        }

        if ($gift->claim_pin && ! $gift->is_claimed) {
            $lines[] = __('gifts.whatsapp.code', ['code' => $gift->claim_pin], 'ar');
        }

        $lines[] = __('gifts.whatsapp.link', $params, 'ar');

        return implode("\n\n", $lines);
    }

    public function giftName(Gift $gift): string
    {
        $gift->loadMissing('order.items');

        return (string) ($gift->order?->items->first()?->product_name ?? $gift->product?->name ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Order contact block. The sender is the paying customer (gateways use it
     * for the buyer checks); there is no delivery address for an online gift.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, Addon>  $addons
     */
    protected function subtotal(Product $product, int $quantity, Collection $addons): float
    {
        return $this->quote($product, $quantity, $addons, null)['subtotal'];
    }

    /**
     * The promo code, locked and re-validated inside the checkout transaction.
     *
     * @throws CheckoutException
     */
    protected function lockedPromo(string $code, User $sender, Product $product, float $subtotal): PromoCode
    {
        $promo = PromoCode::query()->code($code)->lockForUpdate()->first()
            ?? throw CheckoutException::promoInvalid('not_found');

        $this->promos->assertUsable($promo, $sender, $product->store_id, $subtotal);

        return $promo;
    }

    protected function orderContact(User $sender, string $recipientName): array
    {
        $address = $sender->addresses()->default()->first();
        $city = $address?->city ?? City::default()?->key ?? 'riyadh';
        $label = __('gifts.order_address', ['name' => trim($recipientName)]);

        return [
            'shipping_name' => $sender->name,
            'shipping_phone' => $address?->phone ?: $sender->phone,
            'shipping_email' => $sender->email,
            'shipping_location_name' => __('gifts.order_location'),
            'shipping_city' => $city,
            'shipping_district' => '-',
            'shipping_street' => '-',
            'shipping_building_number' => '-',
            'shipping_address' => $label,
        ];
    }

    protected function safely(callable $step, string $label, Gift $gift): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::error("Gift {$label} failed", ['gift_id' => $gift->id, 'error' => $e->getMessage()]);
        }
    }
}
