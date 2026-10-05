<?php

namespace Database\Factories;

use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * مراجعة عميل لطلب منتهٍ: طلب متجر مُسلَّم أو طلب متسوق شخصي مكتمل.
 *
 * بدون forOrder() تُربط كل مراجعة بطلب منتهٍ موجود في قاعدة البيانات لم
 * يُقيَّم بعد (مراجعة واحدة لكل طلب)، وصاحب الطلب هو المُقيِّم.
 *
 * ملاحظة: المصنع يكتب صف reviews فقط. لتحديث تقييم المتجر / المنتجات /
 * المتسوق أيضًا استخدم ReviewService::submit() كما يفعل ReviewSeeder.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @var class-string<\App\Models\Review>
     */
    protected $model = Review::class;

    /**
     * @var array<int, array<int, string>>
     */
    protected const STORE_COMMENTS = [
        5 => ['تعامل راقٍ والتوصيل كان في الموعد بالضبط.', 'أفضل متجر تعاملت معه، التغليف أنيق جدًا.', 'سرعة في التجهيز وتواصل ممتاز.'],
        4 => ['خدمة جيدة لكن التوصيل تأخر قليلًا.', 'تعامل ممتاز، أتمنى تحديثات أكثر عن حالة الطلب.'],
        3 => ['تجربة عادية، التوصيل تأخر ساعة تقريبًا.'],
        2 => ['تأخر الطلب كثيرًا ولم يتم إبلاغي.'],
        1 => ['لم يتم الرد على استفساراتي والطلب وصل متأخرًا جدًا.'],
    ];

    /**
     * @var array<int, array<int, string>>
     */
    protected const SHOPPER_COMMENTS = [
        5 => ['المتسوق اختار هدية رائعة وأرسل لي صورًا قبل الشراء.', 'متعاون جدًا والتزم بالميزانية تمامًا.'],
        4 => ['اختيار موفق، لكن التواصل كان بطيئًا أحيانًا.'],
        3 => ['الهدية مقبولة لكنها ليست كما وصفت تمامًا.'],
        2 => ['تجاوز الوقت المتفق عليه بكثير.'],
        1 => ['اشترى منتجًا مختلفًا عن طلبي.'],
    ];

    /**
     * @var array<int, array<int, string>>
     */
    protected const PRODUCT_COMMENTS = [
        5 => ['المنتج مطابق للصور وجودته عالية.', 'الورد طازج ورائحته جميلة جدًا.', 'الهدية أعجبت صاحبها كثيرًا.'],
        4 => ['المنتج جميل لكن الحجم أصغر قليلًا من المتوقع.', 'جودة جيدة مقابل السعر.'],
        3 => ['المنتج مقبول، كنت أتوقع أفضل.'],
        2 => ['التغليف كان تالفًا عند الاستلام.'],
        1 => ['المنتج وصل مختلفًا عن الصورة.'],
    ];

    /**
     * Orders already handed out by this factory instance, so a count(n) batch
     * (made before anything is inserted) never picks the same order twice.
     *
     * @var array<int, string>
     */
    protected array $claimed = [];

    /**
     * The order picked for the instance being made.
     */
    protected Order|CustomOrder|null $picked = null;

    /**
     * Closures run in key order and only when not overridden, so forOrder()
     * never touches the pool of unreviewed orders.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // أغلب التقييمات إيجابية كما في المتاجر الحقيقية، والمنتجات قريبة من تقييم المتجر
        $storeRating    = fake()->randomElement([5, 5, 5, 5, 4, 4, 4, 3, 2, 1]);
        $productsRating = max(Review::MIN_RATING, min(Review::MAX_RATING, $storeRating + fake()->randomElement([-1, 0, 0, 0, 1])));

        return [
            'reviewable_type'  => fn () => ($this->picked = $this->nextUnreviewedOrder())->getMorphClass(),
            'reviewable_id'    => fn () => $this->picked?->getKey(),
            'user_id'          => fn () => $this->picked?->user_id,
            'store_rating'     => $storeRating,
            'store_comment'    => fn (array $a) => fake()->boolean(80) ? $this->storeComment($a['reviewable_type'], $a['store_rating']) : null,
            'products_rating'  => $productsRating,
            'products_comment' => fn (array $a) => fake()->boolean(70) ? fake()->randomElement(self::PRODUCT_COMMENTS[$a['products_rating']]) : null,
            'created_at'       => fn (array $a) => fake()->dateTimeBetween($this->finishedAt($a['reviewable_type'], $a['reviewable_id']), 'now'),
        ];
    }

    /**
     * Review this specific order (by its owner). The order should be finished
     * and not reviewed yet: reviews are unique per order.
     */
    public function forOrder(Order|CustomOrder $order): static
    {
        return $this->state(fn () => [
            'reviewable_type' => $order->getMorphClass(),
            'reviewable_id'   => $order->getKey(),
            'user_id'         => $order->user_id,
        ]);
    }

    /**
     * Both ratings fixed to $stars, with comments to match.
     */
    public function rating(int $stars): static
    {
        return $this->state(fn () => [
            'store_rating'     => $stars,
            'store_comment'    => fn (array $a) => $this->storeComment($a['reviewable_type'], $stars),
            'products_rating'  => $stars,
            'products_comment' => fake()->randomElement(self::PRODUCT_COMMENTS[$stars]),
        ]);
    }

    public function withoutComments(): static
    {
        return $this->state(fn () => ['store_comment' => null, 'products_comment' => null]);
    }

    /**
     * A random finished order that has an owner and no review yet.
     *
     * @throws RuntimeException  when there is none left
     */
    protected function nextUnreviewedOrder(): Order|CustomOrder
    {
        $queries = [
            Order::query()->where('status', Order::STATUS_DELIVERED),
            CustomOrder::query()->where('status', CustomOrder::STATUS_COMPLETED),
        ];

        foreach (fake()->shuffleArray($queries) as $query) {
            $model = $query->getModel();
            $taken = collect($this->claimed)
                ->filter(fn (string $key) => str_starts_with($key, $model->getMorphClass().':'))
                ->map(fn (string $key) => (int) substr($key, strpos($key, ':') + 1));

            $order = $query->whereHas('user')
                ->doesntHave('review')
                ->whereKeyNot($taken->all())
                ->inRandomOrder()
                ->first();

            if ($order) {
                $this->claimed[] = $order->getMorphClass().':'.$order->getKey();

                return $order;
            }
        }

        throw new RuntimeException('ReviewFactory: no finished order without a review left. Seed orders first or use forOrder().');
    }

    /**
     * When the order was delivered / completed: reviews never predate it.
     */
    protected function finishedAt(string $type, int $id): \DateTimeInterface
    {
        $order = $type === Review::TYPE_CUSTOM_ORDER ? CustomOrder::query()->find($id) : Order::query()->find($id);
        $at    = $order instanceof Order ? $order->delivered_at : $order?->completed_at;

        return $at ?? $order?->updated_at ?? now()->subDay();
    }

    /**
     * The store, or the personal shopper for a custom order.
     */
    protected function storeComment(string $type, int $stars): string
    {
        $comments = $type === Review::TYPE_CUSTOM_ORDER ? self::SHOPPER_COMMENTS : self::STORE_COMMENTS;

        return fake()->randomElement($comments[$stars]);
    }
}
