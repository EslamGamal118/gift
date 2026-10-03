<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\DeliverySlot;
use App\Models\NotificationContent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\CheckoutService;
use App\Services\DeliverySchedulingService;
use App\Services\NotificationService;
use App\Support\OrderStateMachine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demo orders for the merchant app: paid orders in every status, placed by
 * mock customers (avatar, name, phone, Riyadh address) with the store's own
 * products. Fees come from DeliverySchedulingService::quote() and totals from
 * CheckoutService::totals(), so the numbers match real checkouts,
 * and every step has its timestamp + order_status_histories row.
 *
 * Seeded orders carry `payment_data.demo = true`; `$fresh` removes only those.
 *
 *   php artisan db:seed --class=StoreOrderSeeder     (the test store, 24 orders)
 *   php artisan orders:demo {store} --count=40 --fresh
 */
class StoreOrderSeeder extends Seeder
{
    /**
     * Owner of the fixed test store from StoreProfileSeeder.
     */
    public const DEFAULT_STORE_PHONE = '966510000000';

    /**
     * Badge => weight in the generated mix.
     */
    public const STATUS_MIX = [
        'new' => 5,
        'accepted' => 3,
        'preparing' => 4,
        'ready_for_pickup' => 3,
        'out_for_delivery' => 2,
        'completed' => 5,
        'cancelled' => 2,
    ];

    /**
     * The happy path; an order in status X has passed through every earlier step.
     */
    protected const FLOW = [
        Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_PROCESSING,
        Order::STATUS_READY, Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_DELIVERED,
    ];

    protected const CUSTOMERS = [
        'سارة العتيبي', 'محمد القحطاني', 'نورة الشهري', 'عبدالله الدوسري', 'ريم الغامدي',
        'فهد الحربي', 'لمى الزهراني', 'خالد المطيري', 'هند السبيعي', 'تركي العنزي',
    ];

    protected const ADDRESSES = [
        ['location_name' => 'المنزل', 'district' => 'العليا', 'street' => 'طريق الملك فهد', 'lat' => 24.6907, 'lng' => 46.6853],
        ['location_name' => 'العمل', 'district' => 'الملقا', 'street' => 'طريق أنس بن مالك', 'lat' => 24.8094, 'lng' => 46.6096],
        ['location_name' => 'المنزل', 'district' => 'النرجس', 'street' => 'شارع الأمير سعود بن عبدالله', 'lat' => 24.8531, 'lng' => 46.6629],
        ['location_name' => 'بيت الأهل', 'district' => 'الياسمين', 'street' => 'شارع الثمامة', 'lat' => 24.8243, 'lng' => 46.6440],
        ['location_name' => 'المنزل', 'district' => 'حطين', 'street' => 'شارع الأمير محمد بن سعد', 'lat' => 24.7622, 'lng' => 46.5987],
        ['location_name' => 'الشقة', 'district' => 'الروضة', 'street' => 'شارع خالد بن الوليد', 'lat' => 24.7308, 'lng' => 46.7714],
    ];

    protected const GIFT_MESSAGES = [
        'كل عام وأنتِ بخير يا أمي ❤️', 'مبروك التخرج! فخورين فيك', 'عيد ميلاد سعيد يا صديقي', null, null,
    ];

    protected const CANCEL_REASONS = [
        'نفاد الكمية من المنتج المطلوب', 'تعذّر التوصيل في الموعد المحدد', 'طلب العميل الإلغاء عبر الهاتف',
    ];

    public ?User $store = null;

    public int $count = 24;

    public bool $fresh = false;

    public function __construct(
        protected CheckoutService $checkout,
        protected DeliverySchedulingService $delivery,
        protected NotificationService $notifications,
    ) {}

    public function run(): void
    {
        $store = $this->store ?? User::query()->ofType(User::TYPE_STORE)->where('phone', self::DEFAULT_STORE_PHONE)->first();

        if (! $store) {
            $this->command?->warn('StoreOrderSeeder: no store account found, skipping. Run StoreProfileSeeder first or pass a store.');

            return;
        }

        $created = DB::transaction(function () use ($store) {
            if ($this->fresh) {
                $this->command?->line('Removed '.$this->deleteDemoOrders($store).' earlier demo orders.');
            }

            $products = $this->productsFor($store);
            $customers = $this->customers();
            $captain = User::query()->active()->ofType(User::TYPE_CAPTAIN)->first();
            $slots = DeliverySlot::query()->active()->ordered()->get();

            $orders = collect();

            foreach ($this->statusPlan() as $i => $badge) {
                $orders->push($this->createOrder($store, $customers[$i % $customers->count()], $products, $badge, $i, $captain, $slots));
            }

            return $orders;
        });

        $this->command?->info(sprintf('Seeded %d paid demo orders for store #%d (%s).', $created->count(), $store->id, $store->phone));
    }

    /**
     * Delete this store's earlier demo orders (items, addons and history cascade)
     * and the notifications that point at them.
     */
    public function deleteDemoOrders(User $store): int
    {
        $ids = Order::query()->forStore($store->id)->where('payment_data->demo', true)->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            NotificationContent::query()
                ->where('type', NotificationContent::TYPE_ORDER_STATUS)
                ->whereIn('data->order_id', $chunk->map(fn ($id) => (string) $id)->values()->all())
                ->delete();
        }

        return Order::query()->whereKey($ids)->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Building blocks
    |--------------------------------------------------------------------------
    */

    /**
     * `count` badges: one of each first (so small runs still cover every
     * status), the rest following STATUS_MIX, shuffled so the list looks organic.
     *
     * @return list<string>
     */
    protected function statusPlan(): array
    {
        $plan = array_keys(self::STATUS_MIX);

        foreach (self::STATUS_MIX as $badge => $weight) {
            array_push($plan, ...array_fill(0, $weight - 1, $badge));
        }

        while (count($plan) < $this->count) {
            $plan[] = fake()->randomElement(array_merge(...array_map(
                fn (string $badge, int $weight) => array_fill(0, $weight, $badge),
                array_keys(self::STATUS_MIX), self::STATUS_MIX,
            )));
        }

        $plan = array_slice($plan, 0, $this->count);
        shuffle($plan);

        return $plan;
    }

    /**
     * The store's products, or a small catalogue created for it when it has none.
     *
     * @return Collection<int, Product>
     */
    protected function productsFor(User $store): Collection
    {
        $products = Product::query()->where('store_id', $store->id)->get();

        return $products->isNotEmpty()
            ? $products
            : Product::factory()->count(6)->create(['store_id' => $store->id]);
    }

    /**
     * Mock customers (reused across runs by phone) with one saved address each.
     *
     * @return Collection<int, User>
     */
    protected function customers(): Collection
    {
        return collect(self::CUSTOMERS)->map(function (string $name, int $i) {
            $customer = User::query()->firstOrCreate(['phone' => sprintf('9665990000%02d', $i + 1)], [
                'name' => $name,
                'avatar' => 'https://i.pravatar.cc/150?img='.($i + 11),
                'user_type' => User::TYPE_CUSTOMER,
                'status' => 'active',
                'locale' => 'ar',
                'phone_verified_at' => now(),
            ]);

            $spot = self::ADDRESSES[$i % count(self::ADDRESSES)];

            $customer->setRelation('demoAddress', UserAddress::query()->firstOrCreate(
                ['user_id' => $customer->id, 'location_name' => $spot['location_name']],
                [
                    'city' => 'الرياض',
                    'district' => $spot['district'],
                    'street' => $spot['street'],
                    'building_number' => (string) (1000 + $i * 137),
                    'phone' => $customer->phone,
                    'latitude' => $spot['lat'] + $i * 0.0011,
                    'longitude' => $spot['lng'] - $i * 0.0009,
                    'is_default' => true,
                ],
            ));

            return $customer;
        });
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, DeliverySlot>  $slots
     */
    protected function createOrder(User $store, User $customer, Collection $products, string $badge, int $index, ?User $captain, Collection $slots): Order
    {
        $status = array_search($badge, Order::STORE_BADGES, true);
        $isCancelled = $status === Order::STATUS_CANCELLED;
        $isHistory = in_array($status, Order::HISTORY_STATUSES, true);

        // Active orders are recent (minutes/hours), finished ones spread over two weeks
        $placedAt = $isHistory
            ? now()->subDays(fake()->numberBetween(1, 14))->setTime(fake()->numberBetween(9, 22), fake()->numberBetween(0, 59))
            : now()->subMinutes(fake()->numberBetween(3 + $index * 4, 30 + $index * 12));

        $lines = $products->random(min($products->count(), fake()->numberBetween(1, 4)))->map(fn (Product $p) => [
            'product' => $p,
            'quantity' => fake()->randomElement([1, 1, 1, 2, 2, 3]),
        ]);
        $subtotal = round($lines->sum(fn ($l) => (float) $l['product']->price * $l['quantity']), 2);

        /** @var UserAddress $address */
        $address = $customer->getRelation('demoAddress');
        $instant = $index % 3 === 0 || $slots->isEmpty();
        $quote = $this->delivery->quote($store->storeProfile, $address, $instant ? Cart::DELIVERY_INSTANT : Cart::DELIVERY_SCHEDULED);

        $discount = $index % 4 === 1 ? round($subtotal * 0.10, 2) : 0.0;
        $totals = $this->checkout->totals($subtotal, $discount, $quote['delivery_fee'], $quote['express_fee']);
        $delivery = $this->deliveryFor($placedAt, $instant, $slots, $quote['eta_minutes']);

        $order = new Order;
        $order->forceFill([
            'order_number' => Order::generateNumber(),
            'user_id' => $customer->id,
            'store_id' => $store->id,
            'captain_id' => in_array($status, [Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_DELIVERED], true) ? $captain?->id : null,
            'address_id' => $address->id,
            'shipping_name' => $customer->name,
            'shipping_phone' => $customer->phone,
            'shipping_location_name' => $address->location_name,
            'shipping_city' => $address->city,
            'shipping_district' => $address->district,
            'shipping_street' => $address->street,
            'shipping_building_number' => $address->building_number,
            'shipping_address' => $address->toLine(),
            'shipping_latitude' => $address->latitude,
            'shipping_longitude' => $address->longitude,
            'gift_message' => fake()->randomElement(self::GIFT_MESSAGES),
            'promo_code' => $discount > 0 ? 'GIFT10' : null,
            'currency' => $totals['currency'] ?? 'SAR',
            'subtotal' => $totals['subtotal'],
            'delivery_fee' => $totals['delivery_fee'],
            'express_fee' => $totals['express_fee'],
            'discount_amount' => $totals['discount'],
            'tax_rate' => $totals['tax_rate'],
            'tax_amount' => $totals['tax'],
            'total_amount' => $totals['total'],
            'payment_method' => fake()->randomElement([Order::METHOD_ALRAJHI, Order::METHOD_ALRAJHI, Order::METHOD_TAMARA, Order::METHOD_TABBY]),
            'payment_status' => Order::PAYMENT_PAID,
            'payment_data' => ['demo' => true, 'reference' => 'DEMO-'.strtoupper(fake()->bothify('??####??'))],
            'paid_at' => $placedAt->copy()->addMinute(),
            'status' => Order::STATUS_PENDING,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ] + $delivery)->save();

        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->name,
                'product_image' => $line['product']->image,
                'unit_price' => $line['product']->price,
                'quantity' => $line['quantity'],
                'addons_total' => 0,
                'subtotal' => round((float) $line['product']->price * $line['quantity'], 2),
            ]);
        }

        $this->walkTo($order, $status, $store, $captain, $placedAt->copy()->addMinute());

        // Orders still waiting for the store ring its bell (stored only, never pushed)
        if ($status === Order::STATUS_PENDING) {
            $this->notifications->notifyStoreNewOrder($order, push: false)
                ?->forceFill(['created_at' => $placedAt->copy()->addMinute()])->save();
        }

        return $order;
    }

    /**
     * Delivery snapshot: instant with an ETA, or a slot on the placement day / next day.
     *
     * @param  Collection<int, DeliverySlot>  $slots
     * @param  array{min: int, max: int}|null  $eta
     * @return array<string, mixed>
     */
    protected function deliveryFor(Carbon $placedAt, bool $instant, Collection $slots, ?array $eta): array
    {
        if ($instant) {
            $eta ??= ['min' => 30, 'max' => 45];

            return [
                'delivery_type' => Cart::DELIVERY_INSTANT,
                'delivery_date' => $placedAt->toDateString(),
                'delivery_window_start' => $placedAt->copy()->addMinutes($eta['min']),
                'delivery_window_end' => $placedAt->copy()->addMinutes($eta['max']),
                'estimated_minutes_min' => $eta['min'],
                'estimated_minutes_max' => $eta['max'],
            ];
        }

        /** @var DeliverySlot $slot */
        $slot = $slots->random();
        $day = $placedAt->copy()->addDay()->startOfDay();

        return [
            'delivery_type' => Cart::DELIVERY_SCHEDULED,
            'delivery_date' => $day->toDateString(),
            'delivery_slot_id' => $slot->id,
            'delivery_slot_label' => $slot->timeRange(),
            'delivery_window_start' => $slot->startsAt($day),
            'delivery_window_end' => $slot->endsAt($day),
        ];
    }

    /**
     * Replay the lifecycle up to `$target`, stamping each step a few minutes
     * apart (never in the future) and writing its history row.
     */
    protected function walkTo(Order $order, string $target, User $store, ?User $captain, Carbon $at): void
    {
        $history = [['from' => Order::STATUS_PENDING_PAYMENT, 'to' => Order::STATUS_PENDING, 'actor' => Order::ACTOR_SYSTEM, 'by' => null, 'reason' => null, 'at' => $at->copy()]];

        $path = $target === Order::STATUS_CANCELLED
            ? array_slice(self::FLOW, 0, fake()->numberBetween(1, 3)) // cancelled while new, accepted or preparing
            : array_slice(self::FLOW, 0, array_search($target, self::FLOW, true) + 1);

        $stamps = [];

        foreach (array_slice($path, 1) as $i => $to) {
            $at = $this->step($at);
            $action = $this->actionInto($to);
            $actor = $to === Order::STATUS_DELIVERED && $captain ? Order::ACTOR_CAPTAIN : Order::ACTOR_STORE;

            $stamps[OrderStateMachine::ACTIONS[$action]['stamp']] = $at->copy();
            $history[] = ['from' => $path[$i], 'to' => $to, 'actor' => $actor, 'by' => $actor === Order::ACTOR_CAPTAIN ? $captain : $store, 'reason' => null, 'at' => $at->copy()];
        }

        if ($target === Order::STATUS_CANCELLED) {
            $at = $this->step($at);
            $reason = fake()->randomElement(self::CANCEL_REASONS);

            $stamps += ['cancelled_at' => $at->copy(), 'cancelled_by' => Order::ACTOR_STORE, 'cancellation_reason' => $reason];
            $history[] = ['from' => end($path), 'to' => Order::STATUS_CANCELLED, 'actor' => Order::ACTOR_STORE, 'by' => $store, 'reason' => $reason, 'at' => $at->copy()];
        }

        $order->forceFill($stamps + ['status' => $target, 'updated_at' => $at])->saveQuietly();

        foreach ($history as $row) {
            $order->statusHistories()->make([
                'from_status' => $row['from'],
                'to_status' => $row['to'],
                'actor_type' => $row['actor'],
                'actor_id' => $row['by']?->id,
                'reason' => $row['reason'],
            ])->forceFill(['created_at' => $row['at']])->save();
        }
    }

    protected function step(Carbon $at): Carbon
    {
        $next = $at->copy()->addMinutes(fake()->numberBetween(2, 25));

        // Recent orders run out of room: squeeze the remaining steps just before now
        return $next->isFuture() ? $at->copy()->addSeconds(20)->min(now()) : $next;
    }

    protected function actionInto(string $status): string
    {
        foreach (OrderStateMachine::ACTIONS as $action => $rule) {
            if ($rule['to'] === $status) {
                return $action;
            }
        }

        throw new \LogicException("No action leads to {$status}");
    }
}
