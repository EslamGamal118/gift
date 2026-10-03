<?php

namespace Database\Seeders;

use App\Models\CustomOrder;
use App\Models\CustomOrderAlternative;
use App\Models\CustomOrderBid;
use App\Models\DeliverySlot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo custom (personal shopper) orders in every status, for the customer
 * app (/user/custom-orders, /custom-orders) and the shopper app (/shopper/orders).
 *
 * Orders go mostly to the fixed test customer (966500000001) and the fixed
 * test shopper (966530000000), the rest to other active customers / approved
 * shoppers. Each order has 2-4 items, a Saudi delivery address snapshot, the
 * budget computed from the items like CustomOrderService does, and the
 * timestamps of every step it went through. Open-for-bidding orders get bids.
 *
 *   php artisan db:seed --class=CustomOrderSeeder
 *
 * Requires UserSeeder + ShopperProfileSeeder (and DeliverySlotSeeder for slots).
 * Each run adds a new batch.
 */
class CustomOrderSeeder extends Seeder
{
    public const TEST_CUSTOMER_PHONE = '966500000001';

    public const TEST_SHOPPER_PHONE = '966530000000';

    /**
     * [status, scenario, how many]. Scenarios: `direct` (shopper chosen by the
     * customer), `bidding` (open to offers, no shopper yet), `none` (draft
     * before step 2).
     *
     * Active tab: pending / accepted / in_progress / waiting_for_alternative /
     * waiting_for_payment. History: completed (paid) / cancelled.
     */
    protected const PLAN = [
        [CustomOrder::STATUS_DRAFT, 'none', 1],
        [CustomOrder::STATUS_DRAFT, 'direct', 1],
        [CustomOrder::STATUS_PENDING, 'direct', 3],
        [CustomOrder::STATUS_PENDING, 'bidding', 2],
        [CustomOrder::STATUS_ACCEPTED, 'direct', 3],
        [CustomOrder::STATUS_IN_PROGRESS, 'direct', 3],
        [CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE, 'direct', 2],
        [CustomOrder::STATUS_WAITING_FOR_PAYMENT, 'direct', 2],
        [CustomOrder::STATUS_COMPLETED, 'direct', 6],
        [CustomOrder::STATUS_CANCELLED, 'direct', 3],
    ];

    /**
     * name, description, expected unit price range (SAR).
     */
    protected const PRODUCTS = [
        ['عطر عود ملكي 100 مل', 'من العربية للعود، الإصدار الأسود', 380, 520],
        ['دهن عود كمبودي 3 تولة', 'معتق، يفضل من محلات البلد', 450, 750],
        ['بوكس شوكولاتة باتشي', 'تشكيلة مشكلة 1 كيلو مع تغليف هدية', 180, 260],
        ['باقة ورد جوري أحمر', '25 وردة مع كرت إهداء', 150, 280],
        ['ساعة يد نسائية', 'لون ذهبي، سوار معدني', 600, 1100],
        ['شماغ البسام الأحمر', 'مقاس 58، موديل السنة', 160, 240],
        ['مبخرة كهربائية', 'تعمل بالشحن، لون أسود', 90, 180],
        ['كيكة عيد ميلاد', 'شوكولاتة بلجيكية، تكفي 12 شخص، مكتوب عليها الاسم', 140, 220],
        ['طقم فناجين قهوة', '12 فنجان مع دلة، نقشة ذهبية', 220, 380],
        ['تمر سكري فاخر', 'علبة 3 كيلو من القصيم', 120, 200],
        ['سماعات AirPods Pro', 'الجيل الثاني، ضمان الوكيل', 850, 1000],
        ['محفظة جلد رجالية', 'جلد طبيعي، لون بني', 150, 300],
        ['بخور معمول', 'علبة نصف كيلو', 100, 160],
        ['ألعاب أطفال ليغو', 'عمر 6-8 سنوات، مجموعة متوسطة', 200, 350],
    ];

    protected const ADDRESSES = [
        ['city' => 'الرياض', 'district' => 'العليا', 'street' => 'طريق الملك فهد', 'location' => 'المنزل', 'lat' => 24.6907, 'lng' => 46.6853],
        ['city' => 'الرياض', 'district' => 'الملقا', 'street' => 'طريق أنس بن مالك', 'location' => 'العمل', 'lat' => 24.8094, 'lng' => 46.6096],
        ['city' => 'الرياض', 'district' => 'النرجس', 'street' => 'شارع الأمير سعود بن عبدالله', 'location' => 'المنزل', 'lat' => 24.8531, 'lng' => 46.6629],
        ['city' => 'الرياض', 'district' => 'الياسمين', 'street' => 'شارع الثمامة', 'location' => 'بيت الأهل', 'lat' => 24.8243, 'lng' => 46.6440],
        ['city' => 'الرياض', 'district' => 'حطين', 'street' => 'شارع الأمير محمد بن سعد', 'location' => 'المنزل', 'lat' => 24.7622, 'lng' => 46.5987],
        ['city' => 'جدة', 'district' => 'الروضة', 'street' => 'شارع الأمير سلطان', 'location' => 'المنزل', 'lat' => 21.5636, 'lng' => 39.1531],
        ['city' => 'جدة', 'district' => 'الشاطئ', 'street' => 'طريق الكورنيش', 'location' => 'الشقة', 'lat' => 21.5986, 'lng' => 39.1083],
        ['city' => 'الدمام', 'district' => 'الشاطئ الغربي', 'street' => 'طريق الخليج', 'location' => 'المنزل', 'lat' => 26.4473, 'lng' => 50.1105],
    ];

    protected const RECIPIENTS = ['سارة العتيبي', 'نورة الشهري', 'عبدالله الدوسري', 'ريم الغامدي', 'فهد الحربي', 'لمى الزهراني'];

    protected const NOTES = [
        'هدية لأمي بمناسبة عيد الأم، أرجو التغليف بشكل أنيق',
        'إذا ما توفر اللون الذهبي خذ الفضي',
        'هدية تخرج، يفضل إضافة كرت تهنئة',
        null,
        null,
    ];

    protected const CANCELLATIONS = [
        [CustomOrder::ACTOR_CUSTOMER, 'غيرت رأيي في الهدية'],
        [CustomOrder::ACTOR_SHOPPER, 'المنتج غير متوفر في المحلات القريبة'],
        [CustomOrder::ACTOR_SYSTEM, 'لم يقبل المتسوق الطلب في الوقت المحدد'],
    ];

    public function run(): void
    {
        $customers = $this->customers();
        $shoppers  = $this->shoppers();

        if ($customers->isEmpty() || $shoppers->isEmpty()) {
            $this->command?->warn('CustomOrderSeeder: needs active customers and approved shoppers. Run UserSeeder and ShopperProfileSeeder first.');

            return;
        }

        $slots = DeliverySlot::query()->active()->ordered()->get();

        $orders = DB::transaction(function () use ($customers, $shoppers, $slots) {
            $orders = collect();
            $i      = 0;

            foreach (self::PLAN as [$status, $scenario, $count]) {
                for ($n = 0; $n < $count; $n++, $i++) {
                    // Test accounts get most orders, so both apps have something to show
                    $customer = $i % 3 === 2 ? $customers->random() : $customers->first();
                    $shopper  = $i % 4 === 3 ? $shoppers->random() : $shoppers->first();

                    $orders->push($this->createOrder($customer, $scenario === 'direct' ? $shopper : null, $status, $scenario, $i, $slots, $shoppers));
                }
            }

            return $orders;
        });

        $this->command?->info(sprintf(
            'Seeded %d custom orders (%s).',
            $orders->count(),
            $orders->countBy('status')->map(fn ($n, $status) => "{$status}: {$n}")->implode(', '),
        ));
    }

    /**
     * Test customer first, then other active customers.
     *
     * @return Collection<int, User>
     */
    protected function customers(): Collection
    {
        return User::query()->active()->ofType(User::TYPE_CUSTOMER)
            ->orderByRaw('phone = ? desc', [self::TEST_CUSTOMER_PHONE])
            ->orderBy('id')
            ->limit(8)
            ->get();
    }

    /**
     * Test shopper first, then other approved shoppers.
     *
     * @return Collection<int, User>
     */
    protected function shoppers(): Collection
    {
        return User::query()->active()->ofType(User::TYPE_SHOPPER)
            ->whereHas('shopperProfile', fn ($q) => $q->where('status', 'approved'))
            ->orderByRaw('phone = ? desc', [self::TEST_SHOPPER_PHONE])
            ->orderBy('id')
            ->limit(6)
            ->get();
    }

    /**
     * @param  Collection<int, DeliverySlot>  $slots
     * @param  Collection<int, User>  $shoppers
     */
    protected function createOrder(User $customer, ?User $shopper, string $status, string $scenario, int $i, Collection $slots, Collection $shoppers): CustomOrder
    {
        $history   = in_array($status, CustomOrder::HISTORY_STATUSES, true);
        $createdAt = $history
            ? now()->subDays(4 + $i)->setTime(10 + $i % 8, 15 * ($i % 4))     // past orders: days to weeks ago
            : now()->subHours(3 + $i * 5);                                      // current orders: last few days

        $items  = $this->items($i);
        $budget = $this->budget($items);

        $order = new CustomOrder;
        $order->forceFill([
            'order_number'    => $this->orderNumber($createdAt),
            'user_id'         => $customer->id,
            'shopper_id'      => $shopper?->id,
            'assignment_mode' => match ($scenario) {
                'direct'  => CustomOrder::MODE_DIRECT,
                'bidding' => CustomOrder::MODE_BIDDING,
                default   => null,
            },
            'notes'           => self::NOTES[$i % count(self::NOTES)],
            'currency'        => 'SAR',
            'budget_min'      => $budget['min'],
            'budget_max'      => $budget['max'],
            'status'          => $status,
            'created_at'      => $createdAt,
            'updated_at'      => $createdAt,
        ] + ($status === CustomOrder::STATUS_DRAFT ? [] : $this->address($customer, $i) + $this->schedule($createdAt, $history, $i, $slots))
          + $this->timeline($status, $scenario, $createdAt, $budget, $i));
        $order->save();

        $order->items()->createMany($items);

        if ($scenario === 'bidding') {
            $this->bids($order, $shoppers, $budget, $createdAt);
        }

        if ($status === CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE) {
            $this->alternative($order, $createdAt);
        }

        return $order;
    }

    /**
     * 2-4 different products, 1-2 of each.
     *
     * @return list<array<string, mixed>>
     */
    protected function items(int $i): array
    {
        $count = 2 + $i % 3;

        return array_map(function (int $k) use ($i) {
            [$name, $description, $min, $max] = self::PRODUCTS[($i * 3 + $k * 5) % count(self::PRODUCTS)];

            return [
                'product_name'       => $name,
                'description'        => $description,
                'quantity'           => ($i + $k) % 4 === 0 ? 2 : 1,
                'expected_price_min' => $min,
                'expected_price_max' => $max,
                'sort_order'         => $k,
            ];
        }, range(0, $count - 1));
    }

    /**
     * Sum of quantity x expected range, as CustomOrderService computes it.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{min: float, max: float}
     */
    protected function budget(array $items): array
    {
        return [
            'min' => round(array_sum(array_map(fn ($item) => $item['expected_price_min'] * $item['quantity'], $items)), 2),
            'max' => round(array_sum(array_map(fn ($item) => $item['expected_price_max'] * $item['quantity'], $items)), 2),
        ];
    }

    /**
     * Delivery address snapshot (set when the order is confirmed).
     *
     * @return array<string, mixed>
     */
    protected function address(User $customer, int $i): array
    {
        $address  = self::ADDRESSES[$i % count(self::ADDRESSES)];
        $building = (string) (1000 + ($i * 137) % 8000);
        $forOther = $i % 3 === 1;   // some gifts go straight to the recipient

        return [
            'delivery_name'            => $forOther ? self::RECIPIENTS[$i % count(self::RECIPIENTS)] : $customer->name,
            'delivery_phone'           => $forOther ? sprintf('9665%08d', 50000000 + $i * 7919) : $customer->phone,
            'delivery_location_name'   => $address['location'],
            'delivery_city'            => $address['city'],
            'delivery_district'        => $address['district'],
            'delivery_street'          => $address['street'],
            'delivery_building_number' => $building,
            'delivery_address'         => "{$building}، {$address['street']}، {$address['district']}، {$address['city']}",
            'delivery_latitude'        => $address['lat'] + ($i % 5) * 0.002,
            'delivery_longitude'       => $address['lng'] - ($i % 4) * 0.002,
        ];
    }

    /**
     * Delivery slot (or exact time when no slots are configured): upcoming for
     * current orders, a day or two after the order for past ones.
     *
     * @param  Collection<int, DeliverySlot>  $slots
     * @return array<string, mixed>
     */
    protected function schedule(Carbon $createdAt, bool $history, int $i, Collection $slots): array
    {
        $day = ($history ? $createdAt->copy()->addDays(1 + $i % 2) : now()->addDays(1 + $i % 3))->startOfDay();

        if ($slots->isEmpty()) {
            $at = $day->copy()->setTime(18, 0);

            return ['delivery_at' => $at, 'delivery_date' => $day->toDateString()];
        }

        $slot  = $slots[$i % $slots->count()];
        $start = $slot->startsAt($day);

        return [
            'delivery_at'           => $start,
            'delivery_date'         => $day->toDateString(),
            'delivery_slot_id'      => $slot->id,
            'delivery_slot_label'   => $slot->timeRange(),
            'delivery_window_start' => $start,
            'delivery_window_end'   => $slot->endsAt($day),
        ];
    }

    /**
     * Timestamps of every step the order went through, and the outcome
     * (final amount, cancellation) for finished ones.
     *
     * @param  array{min: float, max: float}  $budget
     * @return array<string, mixed>
     */
    protected function timeline(string $status, string $scenario, Carbon $createdAt, array $budget, int $i): array
    {
        if ($status === CustomOrder::STATUS_DRAFT) {
            return [];
        }

        $submitted = $createdAt->copy()->addMinutes(8);
        $steps     = ['submitted_at' => $submitted]
            + ($scenario === 'bidding' ? ['bidding_opened_at' => $submitted] : ['assigned_at' => $submitted]);

        // Cancelled orders stopped at different points; alternatives are suggested before or while shopping
        $reached = match ($status) {
            CustomOrder::STATUS_CANCELLED                => [CustomOrder::STATUS_PENDING, CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_IN_PROGRESS][$i % 3],
            CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE => [CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_IN_PROGRESS][$i % 2],
            default                                      => $status,
        };

        $order = [
            CustomOrder::STATUS_PENDING, CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_IN_PROGRESS,
            CustomOrder::STATUS_WAITING_FOR_PAYMENT, CustomOrder::STATUS_COMPLETED,
        ];
        $depth = array_search($reached, $order, true);

        if ($depth >= 1) {
            $steps['accepted_at'] = $createdAt->copy()->addMinutes(35);
        }
        if ($depth >= 2) {
            $steps['started_at'] = $createdAt->copy()->addHours(3);
        }
        if ($depth >= 3) {
            // Purchased: what the shopper actually spent, somewhere inside the budget
            $steps['purchased_at'] = $status === CustomOrder::STATUS_COMPLETED
                ? $createdAt->copy()->addDay()->setTime(18, 0)
                : $createdAt->copy()->addHours(2);
            $steps['final_amount'] = round($budget['min'] + ($budget['max'] - $budget['min']) * (0.3 + 0.1 * ($i % 6)), 2);
        }
        if ($status === CustomOrder::STATUS_COMPLETED) {
            // Completed = paid by the customer
            $steps['completed_at']   = $createdAt->copy()->addDay()->setTime(19, 30);
            $steps['paid_at']        = $steps['completed_at'];
            $steps['payment_status'] = CustomOrder::PAYMENT_PAID;
            $steps['payment_method'] = 'alrajhi';
        }
        if ($status === CustomOrder::STATUS_CANCELLED) {
            [$by, $reason] = self::CANCELLATIONS[$i % count(self::CANCELLATIONS)];

            $steps += [
                'cancelled_at'        => $createdAt->copy()->addHours(4),
                'cancelled_by'        => $by,
                'cancellation_reason' => $reason,
            ];
        }

        $steps['updated_at'] = collect($steps)->filter(fn ($value) => $value instanceof Carbon)->max();

        return $steps;
    }

    /**
     * A pending alternative for the order's first item, waiting for the customer.
     */
    protected function alternative(CustomOrder $order, Carbon $createdAt): void
    {
        $item = $order->items()->orderBy('sort_order')->firstOrFail();
        $at   = $createdAt->copy()->addHours(4);

        $alternative = new CustomOrderAlternative;
        $alternative->forceFill([
            'custom_order_id'      => $order->id,
            'custom_order_item_id' => $item->id,
            'shopper_id'           => $order->shopper_id,
            'product_name'         => $item->product_name.' (smaller size)',
            'price'                => $item->expected_price_max ?? $item->expected_price_min ?? 50,
            'currency'             => $order->currency,
            'reason'               => 'The requested size is out of stock',
            'status'               => CustomOrderAlternative::STATUS_PENDING,
            'created_at'           => $at,
            'updated_at'           => $at,
        ])->save();
    }

    /**
     * 2-3 pending offers from approved shoppers on an order open for bidding.
     *
     * @param  Collection<int, User>  $shoppers
     * @param  array{min: float, max: float}  $budget
     */
    protected function bids(CustomOrder $order, Collection $shoppers, array $budget, Carbon $createdAt): void
    {
        $messages = ['أقدر أوفرها لك اليوم قبل المغرب', 'أعرف محل عنده نفس المنتج بسعر أقل', 'متوفرة عندي خبرة في هالنوع من الهدايا'];

        foreach ($shoppers->take(2 + $order->id % 2)->values() as $k => $shopper) {
            $fee = 25 + 10 * $k;

            CustomOrderBid::query()->create([
                'custom_order_id' => $order->id,
                'shopper_id'      => $shopper->id,
                'amount'          => round($budget['min'] + ($budget['max'] - $budget['min']) * (0.4 + 0.15 * $k), 2) + $fee,
                'service_fee'     => $fee,
                'delivery_at'     => $order->delivery_at,
                'message'         => $messages[$k % count($messages)],
                'status'          => 'pending',
            ])->forceFill(['created_at' => $createdAt->copy()->addMinutes(20 + 15 * $k)])->save();
        }
    }

    /**
     * Same format as CustomOrder::generateNumber(), dated like the order.
     */
    protected function orderNumber(Carbon $createdAt): string
    {
        $prefix = config('custom_orders.number_prefix', 'CO');

        do {
            $number = sprintf('%s-%s-%s', $prefix, $createdAt->format('Ymd'), strtoupper(Str::random(6)));
        } while (CustomOrder::query()->where('order_number', $number)->exists());

        return $number;
    }
}
