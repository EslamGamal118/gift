<?php

namespace Tests\Feature\Store;

use App\Models\Order;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoreStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 15:00 in Riyadh (12:00 UTC); the week began Saturday 19th at 00:00 Riyadh
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));

        $this->customer = User::factory()->customer()->create();
        $this->store = User::factory()->storeOwner()->create();
        StoreProfile::factory()->approved()->create(['user_id' => $this->store->id, 'rating_avg' => 4.76, 'rating_count' => 127]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<int, array{0: Product, 1: int, 2: float}>  $lines  product, quantity, line subtotal
     * @param  array<string, mixed>  $attributes
     */
    protected function order(string $paidAt, string $status, float $total, array $lines, array $attributes = []): Order
    {
        $order = new Order;
        $order->forceFill($attributes + [
            'order_number' => Order::generateNumber(), 'user_id' => $this->customer->id, 'store_id' => $this->store->id,
            'shipping_city' => 'الرياض', 'shipping_district' => 'العليا', 'shipping_street' => 'طريق الملك فهد',
            'shipping_building_number' => '12', 'shipping_address' => '12, طريق الملك فهد, العليا, الرياض',
            'delivery_type' => 'scheduled', 'currency' => 'SAR', 'subtotal' => $total, 'total_amount' => $total,
            'status' => $status, 'payment_status' => Order::PAYMENT_PAID,
            'paid_at' => Carbon::parse($paidAt, 'UTC'), 'created_at' => Carbon::parse($paidAt, 'UTC'),
        ])->save();

        foreach ($lines as [$product, $quantity, $subtotal]) {
            $order->items()->create([
                'product_id' => $product->id, 'product_name' => $product->name, 'product_image' => 'products/'.$product->id.'.jpg',
                'unit_price' => $subtotal / $quantity, 'quantity' => $quantity, 'subtotal' => $subtotal,
            ]);
        }

        return $order;
    }

    public function test_statistics_screen(): void
    {
        $rose = Product::factory()->create(['store_id' => $this->store->id, 'name' => 'باقة ورد الفرح']);
        $chocolate = Product::factory()->create(['store_id' => $this->store->id, 'name' => 'شوكولاتة فاخرة']);
        $gift = Product::factory()->create(['store_id' => $this->store->id, 'name' => 'هدية مميزة']);

        // This week: Saturday, Monday, Wednesday (+ a paid order cancelled on Tuesday, earning nothing)
        $this->order('2026-09-19 09:00:00', Order::STATUS_DELIVERED, 200, [[$rose, 2, 200]]);
        $this->order('2026-09-21 09:00:00', Order::STATUS_OUT_FOR_DELIVERY, 100, [[$chocolate, 1, 100]]);
        $this->order('2026-09-23 10:00:00', Order::STATUS_PENDING, 300, [[$rose, 3, 300]]);
        $this->order('2026-09-22 09:00:00', Order::STATUS_CANCELLED, 500, [[$chocolate, 10, 500]]);

        // Last week: Monday is within the compared stretch, Thursday is not
        $this->order('2026-09-14 09:00:00', Order::STATUS_DELIVERED, 400, [[$gift, 1, 400]]);
        $this->order('2026-09-17 09:00:00', Order::STATUS_DELIVERED, 100, [[$gift, 1, 100]]);

        // Never earning: refunded (still counted as cancelled), unpaid (never counted at all)
        $this->order('2026-09-20 09:00:00', Order::STATUS_CANCELLED, 700, [[$rose, 7, 700]], ['payment_status' => Order::PAYMENT_REFUNDED]);
        $this->order('2026-09-23 11:00:00', Order::STATUS_PENDING_PAYMENT, 999, [[$rose, 9, 999]], ['payment_status' => Order::PAYMENT_PENDING]);

        $data = $this->actingAs($this->store, 'sanctum')->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/store/statistics')->assertOk()->json('data');

        // Weekly card: 200 + 100 + 300 vs 400 at the same point last week
        $this->assertSame(['from' => '2026-09-19', 'to' => '2026-09-25', 'timezone' => 'Asia/Riyadh'], $data['period']);
        $this->assertEquals(600, $data['weekly_earnings']['amount']);
        $this->assertSame('600.00 ر.س', $data['weekly_earnings']['formatted']);
        $this->assertEquals(50, $data['earnings_growth']['value']);
        $this->assertSame('+50% مقارنة بالأسبوع الماضي', $data['earnings_growth']['label']);

        $this->assertSame(['enabled' => false, 'label' => 'طلب تحويل مستحقات', 'endpoint' => null], $data['payout_action']);

        // Counters over every order that reached the store
        $this->assertSame(3, $data['completed_orders_count']);
        $this->assertSame(1, $data['on_the_way_orders_count']);
        $this->assertSame(2, $data['cancelled_orders_count']);
        $this->assertEquals(220, $data['average_order_value']['amount']);   // (200+100+300+400+100) / 5

        // Line chart accumulates, bar chart is per day, both Saturday to Friday
        $this->assertSame('+50%', $data['profit_trend']['growth_percentage']);
        $this->assertSame(['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'], array_column($data['profit_trend']['chart_data'], 'day'));
        $this->assertEquals([200, 200, 300, 300, 600, 600, 600], array_column($data['profit_trend']['chart_data'], 'value'));
        $this->assertEquals([200, 0, 100, 0, 300, 0, 0], array_column($data['daily_sales'], 'amount'));
        $this->assertSame([1, 0, 1, 0, 1, 0, 0], array_column($data['daily_sales'], 'orders_count'));
        $this->assertSame('السبت', $data['daily_sales'][0]['label']);

        // 3 completed / 2 cancelled / 2 in progress of 7: 42.9 / 28.6 / 28.6 -> 43 / 29 / 28
        $this->assertSame(43, $data['order_distribution']['completion_percentage']);
        $this->assertSame(29, $data['order_distribution']['cancellation_percentage']);
        $this->assertSame(28, $data['order_distribution']['processing_percentage']);
        $this->assertSame('مكتمل', $data['order_distribution']['segments'][0]['label']);

        // Best sellers by quantity over earning orders (the cancelled 10 chocolates do not count)
        $this->assertSame([1, 2, 3], array_column($data['top_products'], 'rank'));
        $this->assertSame(['باقة ورد الفرح', 'هدية مميزة', 'شوكولاتة فاخرة'], array_column($data['top_products'], 'product_name'));
        $this->assertSame('طلبان', $data['top_products'][0]['orders_count_label']);
        $this->assertSame(5, $data['top_products'][0]['quantity_sold']);
        $this->assertEquals(500, $data['top_products'][0]['total_revenue']['amount']);
        $this->assertSame('طلب واحد', $data['top_products'][2]['orders_count_label']);

        $this->assertSame(4.8, $data['customer_rating']['rating_average']);
        $this->assertSame(127, $data['customer_rating']['total_reviews_count']);
        $this->assertSame('بناءً على 127 تقييم', $data['customer_rating']['total_reviews_label']);
    }

    public function test_a_store_without_orders_gets_zeroes(): void
    {
        $data = $this->actingAs($this->store, 'sanctum')->getJson('/api/v1/store/statistics')->assertOk()->json('data');

        $this->assertEquals(0, $data['weekly_earnings']['amount']);
        $this->assertSame(0.0, (float) $data['earnings_growth']['value']);
        $this->assertSame('flat', $data['earnings_growth']['trend']);
        $this->assertEquals(0, $data['average_order_value']['amount']);
        $this->assertSame(0, $data['order_distribution']['completion_percentage'] + $data['order_distribution']['cancellation_percentage'] + $data['order_distribution']['processing_percentage']);
        $this->assertSame([], $data['top_products']);
        $this->assertCount(7, $data['daily_sales']);
    }

    public function test_top_products_limit_and_access(): void
    {
        $this->actingAs($this->store, 'sanctum')->getJson('/api/v1/store/statistics?top_products_limit=50')->assertUnprocessable();
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/store/statistics')->assertForbidden();
    }
}
