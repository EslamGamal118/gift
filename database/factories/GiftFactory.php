<?php

namespace Database\Factories;

use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Online gifts in every life-cycle state, consistent with GiftService:
 *
 *   pending / failed / cancelled ─ checkout only, nothing sent
 *   paid ─ QR code + validity, WhatsApp to the recipient, claim PIN by SMS
 *     └ claimed ─ attached to the recipient's account (SMS skipped), or claimed with the PIN
 *         ├ popupDismissed ─ "Not now" on the home screen popup
 *         └ opened ─ seen in the app
 *             └ redeemed ─ QR scanned at the store before expires_at
 *   expired ─ paid more than the validity days ago, never redeemed
 *
 * Lifecycle states include the earlier steps, so they compose freely:
 * `Gift::factory()->expired()->opened()->via('tabby')->whatsappFailed()`.
 *
 * Every gift gets its own `orders` row (+ one line) mirroring its payment.
 * Timestamps are derived from each other (closures run in key order), so keep
 * the order of definition() keys chronological.
 *
 * @extends Factory<Gift>
 */
class GiftFactory extends Factory
{
    /**
     * @var class-string<Gift>
     */
    protected $model = Gift::class;

    protected const GATEWAYS = [Order::METHOD_ALRAJHI, Order::METHOD_TAMARA, Order::METHOD_TABBY];

    protected const FIRST_NAMES = [
        'سارة', 'نورة', 'ريم', 'لمى', 'هند', 'جود', 'رهف', 'شهد', 'العنود', 'غادة', 'منيرة', 'دانة',
        'محمد', 'عبدالله', 'فهد', 'خالد', 'تركي', 'سعود', 'فيصل', 'نايف', 'بندر', 'عبدالعزيز', 'ماجد', 'يزيد',
    ];

    protected const FAMILY_NAMES = [
        'العتيبي', 'القحطاني', 'الشهري', 'الدوسري', 'الغامدي', 'الحربي', 'الزهراني', 'المطيري',
        'السبيعي', 'العنزي', 'الشمري', 'السهلي', 'الرشيدي', 'البقمي', 'العمري', 'الأحمدي',
    ];

    protected const MESSAGES = [
        'كل عام وأنتِ بخير يا أمي ❤️',
        'مبروك التخرج! فخورين فيك 🎓',
        'عيد ميلاد سعيد يا صديقي، عساك من عواده 🎂',
        'ألف مبروك المولود، يتربى في عزكم',
        'شكرًا على كل شيء، هدية بسيطة تليق بك',
        'مبروك الوظيفة الجديدة، بالتوفيق دائمًا 💼',
        'عيدكم مبارك وكل عام وأنتم بخير 🌙',
        'Happy birthday! Enjoy your day 🎉',
        'Congrats on the new home!',
    ];

    /**
     * Cloud API / transport errors as GiftService stores them (max 250 chars).
     */
    protected const WHATSAPP_ERRORS = [
        '(#131026) Message undeliverable: the recipient phone number is not a WhatsApp account',
        '(#131047) Re-engagement message: more than 24 hours have passed since the recipient last replied',
        '(#131049) This message was not delivered to maintain healthy ecosystem engagement',
        '(#130429) Rate limit hit: cloud API message throughput has been reached',
        '(#131000) Something went wrong',
        '(#100) Invalid parameter: to must be a valid phone number',
        '(#190) Error validating access token: Session has expired',
        'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
        'cURL error 6: Could not resolve host: graph.facebook.com',
    ];

    /**
     * @var array<int, int>
     */
    protected static array $validity = [];

    /**
     * An unpaid gift just out of checkout (GiftService::checkout()).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory()->customer(),
            'product_id' => Product::factory(),
            'store_id' => fn (array $a) => Product::query()->whereKey($a['product_id'])->value('store_id')
                ?? User::factory()->storeOwner()->create()->id,

            'recipient_name' => $this->personName(),
            'recipient_phone' => $this->saudiPhone(),
            'recipient_email' => fn () => fake()->boolean(60) ? fake()->unique()->safeEmail() : null,
            // As at checkout: the customer with that phone, if any
            'recipient_id' => fn (array $a) => User::query()->forPhoneAndRole($a['recipient_phone'], User::TYPE_CUSTOMER)->value('id'),
            'gift_message' => fake()->optional(0.75)->randomElement(self::MESSAGES),

            // Recent enough that a paid gift is still valid (see expired())
            'created_at' => fn (array $a) => $this->between(
                now()->subDays(max(1, min(45, $this->validityDays($a['product_id']) - 2))),
                now()->subMinutes(30),
            ),
            'updated_at' => fn (array $a) => $a['created_at'],

            'payment_status' => Order::PAYMENT_PENDING,
            'payment_gateway' => fake()->randomElement(self::GATEWAYS),
            'paid_at' => null,
            'redemption_code' => null,
            'expires_at' => null,

            'is_claimed' => false,
            'claim_code' => fn () => Gift::generateClaimCode(),
            'claim_pin' => null,
            'claimed_at' => null,
            'popup_dismissed_at' => null,
            'opened_at' => null,
            'redeemed_at' => null,

            'whatsapp_status' => Gift::WHATSAPP_PENDING,
            'whatsapp_sent_at' => null,
            'whatsapp_error' => null,
            'sms_status' => Gift::SMS_PENDING,
            'sms_sent_at' => null,

            // Last: needs the resolved sender, store, product and payment
            'order_id' => fn (array $a) => $this->createOrder($a)->id,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    public function via(string $gateway): static
    {
        return $this->state(['payment_gateway' => $gateway]);
    }

    public function failed(): static
    {
        return $this->state(['payment_status' => Order::PAYMENT_FAILED]);
    }

    public function cancelled(): static
    {
        return $this->state(['payment_status' => Order::PAYMENT_CANCELLED]);
    }

    /**
     * Paid, then refunded: the QR stays on the gift but it is no longer redeemable.
     */
    public function refunded(): static
    {
        return $this->paid()->state([
            'payment_status' => Order::PAYMENT_REFUNDED,
        ]);
    }

    /**
     * Paid and waiting for the recipient (GiftService::handlePaid()): QR code,
     * validity, claim PIN, WhatsApp and SMS sent.
     */
    public function paid(): static
    {
        return $this->state([
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at' => fn (array $a) => $this->at($a['created_at'])->addSeconds(fake()->numberBetween(20, 900)),
            'redemption_code' => fn () => Gift::generateRedemptionCode(),
            'expires_at' => fn (array $a) => $this->at($a['paid_at'])->addDays($this->validityDays($a['product_id']))->endOfDay(),
            'claim_pin' => fn (array $a) => $a['is_claimed'] ? null : Gift::generateClaimPin(),
            'whatsapp_status' => Gift::WHATSAPP_SENT,
            'whatsapp_sent_at' => fn (array $a) => $this->sentAfter($a, 'whatsapp_status', Gift::WHATSAPP_SENT),
            'whatsapp_error' => null,
            // Already in the recipient's account: no PIN to text
            'sms_status' => fn (array $a) => $a['is_claimed'] && ! $a['claim_pin'] ? Gift::SMS_SKIPPED : Gift::SMS_SENT,
            'sms_sent_at' => fn (array $a) => $this->sentAfter($a, 'sms_status', Gift::SMS_SENT),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Claiming, opening, redemption
    |--------------------------------------------------------------------------
    */

    /**
     * Attached at payment to the customer account registered with the
     * recipient's phone (created when missing).
     */
    public function claimed(): static
    {
        return $this->paid()->state([
            'is_claimed' => true,
            'recipient_id' => fn (array $a) => $this->recipientAccount($a)->id,
            'claim_pin' => null,
            'claimed_at' => fn (array $a) => $this->at($a['paid_at'])->addSeconds(fake()->numberBetween(1, 5)),
        ]);
    }

    /**
     * Claimed later with the texted PIN, possibly from an account with another
     * phone (POST /gifts/claim). The PIN stays on the gift.
     */
    public function claimedWithPin(): static
    {
        return $this->paid()->state([
            'is_claimed' => true,
            'claim_pin' => fn () => Gift::generateClaimPin(),
            'recipient_id' => fn (array $a) => fake()->boolean(60)
                ? User::factory()->customer()->create(['name' => $a['recipient_name']])->id
                : $this->recipientAccount($a)->id,
            'claimed_at' => fn (array $a) => $this->between(
                $this->at($a['paid_at'])->addMinutes(10),
                $this->at($a['paid_at'])->addDays(5),
                $this->at($a['expires_at']),
            ),
        ]);
    }

    /**
     * Claimed; the recipient tapped "Not now" on the home screen popup.
     */
    public function popupDismissed(): static
    {
        return $this->claimed()->state([
            'popup_dismissed_at' => fn (array $a) => $this->between(
                $this->at($a['claimed_at'])->addMinutes(2),
                $this->at($a['claimed_at'])->addDays(2),
                $this->at($a['expires_at']),
            ),
            'opened_at' => null,
        ]);
    }

    public function opened(): static
    {
        return $this->claimed()->state([
            'opened_at' => fn (array $a) => $this->between(
                $this->at($a['claimed_at'])->addMinutes(1),
                $this->at($a['claimed_at'])->addDays(3),
                $this->at($a['expires_at']),
            ),
        ]);
    }

    /**
     * Scanned at the store before it expired.
     */
    public function redeemed(): static
    {
        return $this->opened()->state([
            'redeemed_at' => fn (array $a) => $this->between(
                $this->at($a['opened_at'])->addMinutes(15),
                $this->at($a['opened_at'])->addDays(20),
                $this->at($a['expires_at'])->subMinute(),
            ),
        ]);
    }

    /**
     * Paid long enough ago that its validity is over. Combine with claimed() /
     * opened() for "received but never used", or alone for "never claimed".
     */
    public function expired(): static
    {
        return $this->paid()->state([
            'created_at' => fn (array $a) => now()
                ->subDays($this->validityDays($a['product_id']) + fake()->numberBetween(1, 120))
                ->subMinutes(fake()->numberBetween(0, 1440)),
        ]);
    }

    /**
     * Still valid for 1–3 more days.
     */
    public function expiringSoon(): static
    {
        return $this->paid()->state([
            'created_at' => fn (array $a) => now()
                ->subDays(max(0, $this->validityDays($a['product_id']) - fake()->numberBetween(1, 3)))
                ->subMinutes(fake()->numberBetween(0, 600)),
        ]);
    }

    /**
     * Sent to an existing account (claimed states attach the gift to it).
     */
    public function forRecipient(User $recipient): static
    {
        return $this->state([
            'recipient_name' => $recipient->name,
            'recipient_phone' => $recipient->phone,
            'recipient_email' => $recipient->email,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications (apply after the lifecycle state)
    |--------------------------------------------------------------------------
    */

    public function whatsappSent(): static
    {
        return $this->state([
            'whatsapp_status' => Gift::WHATSAPP_SENT,
            'whatsapp_sent_at' => fn (array $a) => $this->sentAfter($a, 'whatsapp_status', Gift::WHATSAPP_SENT),
            'whatsapp_error' => null,
        ]);
    }

    /**
     * Retries exhausted; the last API error is kept.
     */
    public function whatsappFailed(?string $error = null): static
    {
        return $this->state([
            'whatsapp_status' => Gift::WHATSAPP_FAILED,
            'whatsapp_sent_at' => null,
            'whatsapp_error' => $error ?? fake()->randomElement(self::WHATSAPP_ERRORS),
        ]);
    }

    /**
     * Queued or still retrying (a retry keeps the last error).
     */
    public function whatsappPending(bool $retrying = false): static
    {
        return $this->state([
            'whatsapp_status' => Gift::WHATSAPP_PENDING,
            'whatsapp_sent_at' => null,
            'whatsapp_error' => $retrying ? fake()->randomElement(self::WHATSAPP_ERRORS) : null,
        ]);
    }

    /**
     * WhatsApp not configured.
     */
    public function whatsappSkipped(): static
    {
        return $this->state(['whatsapp_status' => Gift::WHATSAPP_SKIPPED, 'whatsapp_sent_at' => null, 'whatsapp_error' => null]);
    }

    public function smsSent(): static
    {
        return $this->state([
            'sms_status' => Gift::SMS_SENT,
            'sms_sent_at' => fn (array $a) => $this->sentAfter($a, 'sms_status', Gift::SMS_SENT),
        ]);
    }

    public function smsFailed(): static
    {
        return $this->state(['sms_status' => Gift::SMS_FAILED, 'sms_sent_at' => null]);
    }

    public function smsPending(): static
    {
        return $this->state(['sms_status' => Gift::SMS_PENDING, 'sms_sent_at' => null]);
    }

    /**
     * Testing environment / SMS not configured, or nothing to text.
     */
    public function smsSkipped(): static
    {
        return $this->state(['sms_status' => Gift::SMS_SKIPPED, 'sms_sent_at' => null]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The gift's order: one line, no delivery, payment mirrored from the gift.
     *
     * @param  array<string, mixed>  $a
     */
    protected function createOrder(array $a): Order
    {
        $product = $a['product_id'] ? Product::query()->find($a['product_id']) : null;
        $sender = User::query()->find($a['sender_id']);
        $createdAt = $this->at($a['created_at']);
        $paidAt = $a['paid_at'] ? $this->at($a['paid_at']) : null;

        $unitPrice = (float) ($product->price ?? fake()->randomFloat(2, 50, 500));
        $quantity = fake()->boolean(85) ? 1 : fake()->numberBetween(2, 3);
        $subtotal = round($unitPrice * $quantity, 2);
        $taxRate = (float) config('checkout.tax.rate', 0.15);
        $tax = round($subtotal * $taxRate, 2);

        [$status, $stamps] = match ($a['payment_status']) {
            Order::PAYMENT_PAID => $a['redeemed_at']
                ? [Order::STATUS_DELIVERED, ['accepted_at' => $paidAt->copy()->addMinutes(5), 'delivered_at' => $this->at($a['redeemed_at'])]]
                : [Order::STATUS_PENDING, []],
            Order::PAYMENT_CANCELLED => [Order::STATUS_CANCELLED, [
                'cancelled_at' => $createdAt->copy()->addMinutes(30),
                'cancelled_by' => Order::ACTOR_SYSTEM,
                'cancellation_reason' => 'انتهت مهلة الدفع',
            ]],
            Order::PAYMENT_REFUNDED => [Order::STATUS_CANCELLED, [
                'cancelled_at' => $paidAt->copy()->addHours(fake()->numberBetween(1, 48)),
                'cancelled_by' => Order::ACTOR_STORE,
                'cancellation_reason' => 'نفاد الكمية من المنتج المطلوب',
            ]],
            default => [Order::STATUS_PENDING_PAYMENT, []],      // pending | failed
        };

        $order = new Order;
        $order->forceFill([
            'order_number' => Order::generateNumber(),
            'user_id' => $a['sender_id'],
            'store_id' => $a['store_id'],
            'shipping_name' => $sender?->name,
            'shipping_phone' => $sender?->phone,
            'shipping_email' => $sender?->email,
            'shipping_location_name' => __('gifts.order_location'),
            'shipping_city' => 'riyadh',
            'shipping_district' => '-',
            'shipping_street' => '-',
            'shipping_building_number' => '-',
            'shipping_address' => __('gifts.order_address', ['name' => $a['recipient_name']]),
            'delivery_type' => 'instant',
            'gift_message' => $a['gift_message'],
            'currency' => config('checkout.currency', 'SAR'),
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $tax,
            'total_amount' => round($subtotal + $tax, 2),
            'payment_method' => $a['payment_gateway'],
            'payment_status' => $a['payment_status'],
            'payment_data' => array_filter([
                'gateway' => $a['payment_gateway'],
                'reference' => $paidAt ? strtoupper(fake()->bothify('???-########')) : null,
                'paid_at' => $paidAt?->toIso8601String(),
                'demo' => true,
            ]),
            'paid_at' => $paidAt,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ] + $stamps)->save();

        $order->items()->forceCreate([
            'product_id' => $product?->id,
            'product_name' => $product->name ?? 'بطاقة هدية',
            'product_image' => $product?->image,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'addons_total' => 0,
            'subtotal' => $subtotal,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    protected function recipientAccount(array $a): User
    {
        return User::query()->forPhoneAndRole($a['recipient_phone'], User::TYPE_CUSTOMER)->first()
            ?? User::factory()->customer()->create([
                'name' => $a['recipient_name'],
                'phone' => $a['recipient_phone'],
                'email' => $a['recipient_email'] ?? fake()->unique()->safeEmail(),
            ]);
    }

    /**
     * The send time of a channel when it was sent, else null.
     *
     * @param  array<string, mixed>  $a
     */
    protected function sentAfter(array $a, string $statusKey, string $sent): ?Carbon
    {
        return $a[$statusKey] === $sent && $a['paid_at']
            ? $this->at($a['paid_at'])->addSeconds(fake()->numberBetween(3, 120))
            : null;
    }

    /**
     * Product's gift validity, else the configured default (as GiftService).
     */
    protected function validityDays(?int $productId): int
    {
        $default = (int) config('gifts.validity_days', 90);

        if (! $productId) {
            return $default;
        }

        return static::$validity[$productId] ??= (int) (Product::query()->whereKey($productId)->value('gift_validity_days') ?: $default);
    }

    /**
     * Random moment in [$from, $to], capped at $cap and at now.
     */
    protected function between(Carbon $from, Carbon $to, ?Carbon $cap = null): Carbon
    {
        $to = $to->copy()->min(now())->min($cap ?? $to);

        return $to->lte($from) ? $to : Carbon::createFromTimestamp(fake()->numberBetween($from->getTimestamp(), $to->getTimestamp()));
    }

    protected function at(mixed $value): Carbon
    {
        return Carbon::instance($value instanceof \DateTimeInterface ? $value : Carbon::parse($value));
    }

    protected function personName(): string
    {
        return fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::FAMILY_NAMES);
    }

    /**
     * Normalized Saudi mobile, e.g. 966551234567.
     */
    protected function saudiPhone(): string
    {
        return '9665'.fake()->randomElement(['0', '3', '4', '5', '6', '8', '9']).fake()->numerify('#######');
    }
}
