<?php

namespace Database\Seeders;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * مراجعات العملاء للطلبات المنتهية (طلبات متجر مُسلَّمة وطلبات متسوق مكتملة).
 *
 * كل مراجعة تمر عبر ReviewService::submit() فتُحدَّث معها store_reviews وتقييم
 * المتجر والمنتجات والمتسوق كما يحدث في التطبيق. المراجعة دائمًا من صاحب الطلب،
 * ومراجعة واحدة لكل طلب.
 *
 * تُقيَّم الطلبات المنتهية الموجودة أولًا؛ وإن لم تكفِ للوصول إلى العدد المطلوب
 * (20-50) تُنشأ طلبات متجر مُسلَّمة إضافية لعملاء ومنتجات حقيقية ثم تُقيَّم.
 * قابل لإعادة التشغيل: لا يفعل شيئًا إذا كان لدينا MIN مراجعة أو أكثر.
 */
class ReviewSeeder extends Seeder
{
    public const MIN = 20;

    public const MAX = 50;

    public function __construct(
        protected ReviewService $reviews,
    ) {}

    public function run(): void
    {
        $existing = Review::query()->count();

        if ($existing >= self::MIN) {
            $this->command?->info("ReviewSeeder: {$existing} reviews already exist, skipping.");

            return;
        }

        $target  = fake()->numberBetween(self::MIN, self::MAX) - $existing;
        $created = 0;

        $orders = $this->unreviewedOrders()->take($target);
        $orders = $orders->concat($this->deliveredOrders($target - $orders->count()));

        foreach ($orders as $order) {
            $created += (int) $this->review($order);
        }

        $this->command?->info("ReviewSeeder: {$created} reviews created.");
    }

    /**
     * Finished orders with a customer and no review, store and custom orders mixed.
     *
     * @return Collection<int, Order|CustomOrder>
     */
    protected function unreviewedOrders(): Collection
    {
        $store = Order::query()
            ->where('status', Order::STATUS_DELIVERED)
            ->whereHas('user')
            ->doesntHave('review')
            ->get();

        $custom = CustomOrder::query()
            ->where('status', CustomOrder::STATUS_COMPLETED)
            ->whereHas('user')
            ->doesntHave('review')
            ->get();

        return $store->toBase()->concat($custom)->shuffle();
    }

    /**
     * Submit a factory-made review the way the app does, dated after the order finished.
     */
    protected function review(Order|CustomOrder $order): bool
    {
        $data = Review::factory()->forOrder($order)->raw();

        try {
            Carbon::setTestNow(Carbon::parse($data['created_at']));

            $this->reviews->submit(
                $order->user,
                $order->getMorphClass(),
                $order->getKey(),
                Arr::only($data, ['store_rating', 'store_comment', 'products_rating', 'products_comment']),
            );

            return true;
        } catch (Throwable $e) {
            $this->command?->warn("ReviewSeeder: skipped {$order->getMorphClass()} #{$order->getKey()}: {$e->getMessage()}");

            return false;
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * $count new delivered store orders: an active customer buying 1-2 in-stock
     * products of one visible store, delivered 2-60 days ago.
     *
     * @return Collection<int, Order>
     */
    protected function deliveredOrders(int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $customers = User::query()->where('user_type', 'customer')->where('status', 'active')->get();
        $stores    = StoreProfile::query()->visible()->pluck('user_id');
        $products  = Product::query()->whereIn('store_id', $stores)->notExpired()->inStock()->get()->groupBy('store_id');

        if ($customers->isEmpty() || $products->isEmpty()) {
            $this->command?->warn('ReviewSeeder: no customers or store products to create extra orders, run UserSeeder / ProductSeeder first.');

            return collect();
        }

        return DB::transaction(fn () => collect(range(1, $count))->map(
            fn () => $this->deliveredOrder($customers->random(), $products->random()),
        ));
    }

    /**
     * @param  Collection<int, Product>  $storeProducts
     */
    protected function deliveredOrder(User $customer, Collection $storeProducts): Order
    {
        $deliveredAt = Carbon::instance(fake()->dateTimeBetween('-60 days', '-2 days'));
        $placedAt    = $deliveredAt->copy()->subHours(fake()->numberBetween(3, 48));

        $lines = $storeProducts->random(min(fake()->numberBetween(1, 2), $storeProducts->count()))
            ->map(fn (Product $product) => [
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'unit_price'   => (float) $product->price,
                'quantity'     => $quantity = fake()->numberBetween(1, 2),
                'subtotal'     => round((float) $product->price * $quantity, 2),
            ]);

        $subtotal    = round($lines->sum('subtotal'), 2);
        $deliveryFee = 15.0;
        $tax         = round(($subtotal + $deliveryFee) * 0.15, 2);

        $order = Order::create([
            'order_number'             => Order::generateNumber(),
            'user_id'                  => $customer->id,
            'store_id'                 => $storeProducts->first()->store_id,
            'shipping_name'            => $customer->name,
            'shipping_phone'           => $customer->phone,
            'shipping_city'            => 'Riyadh',
            'shipping_district'        => 'Olaya',
            'shipping_street'          => 'King Fahd Rd',
            'shipping_building_number' => (string) fake()->numberBetween(1, 999),
            'shipping_address'         => 'King Fahd Rd, Olaya, Riyadh',
            'delivery_type'            => 'scheduled',
            'currency'                 => 'SAR',
            'subtotal'                 => $subtotal,
            'delivery_fee'             => $deliveryFee,
            'tax_amount'               => $tax,
            'discount_amount'          => 0,
            'total_amount'             => round($subtotal + $deliveryFee + $tax, 2),
            'status'                   => Order::STATUS_DELIVERED,
            'payment_status'           => Order::PAYMENT_PAID,
            'payment_data'             => ['seeded_by' => self::class],
            'paid_at'                  => $placedAt,
            'delivered_at'             => $deliveredAt,
        ]);

        $order->forceFill(['created_at' => $placedAt, 'updated_at' => $deliveredAt])->saveQuietly();
        $order->items()->createMany($lines->all());

        return $order;
    }
}
