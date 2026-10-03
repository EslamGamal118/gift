<?php

namespace Database\Factories;

use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreReview>
 */
class StoreReviewFactory extends Factory
{
    /**
     * @var class-string<\App\Models\StoreReview>
     */
    protected $model = StoreReview::class;

    /**
     * تعليقات واقعية مصنّفة حسب التقييم.
     *
     * @var array<int, array<int, string>>
     */
    protected const COMMENTS = [
        5 => [
            'تجربة ممتازة، التوصيل كان أسرع من المتوقع والتغليف أنيق جدًا.',
            'المنتج مطابق للصور تمامًا وجودة عالية، أنصح فيه بشدة.',
            'خدمة راقية وتعامل محترم، صار متجري المفضل للهدايا.',
            'الهدية وصلت في الوقت المحدد بالضبط وأعجبت صاحبها كثير.',
        ],
        4 => [
            'جودة ممتازة لكن التوصيل تأخر قليلًا عن الوقت المحدد.',
            'المنتج جميل، كنت أتمنى خيارات تغليف أكثر.',
            'تعامل جيد وسعر مناسب، بس التطبيق ما أعطاني تحديث لحالة الطلب.',
        ],
        3 => [
            'المنتج جيد لكن أصغر من المتوقع مقارنة بالصور.',
            'تجربة عادية، التوصيل تأخر ساعة تقريبًا.',
        ],
        2 => [
            'وصل الطلب ناقص قطعة وتم التعويض بعد التواصل.',
            'التغليف كان تالف والمنتج وصل متأخر.',
        ],
        1 => [
            'الطلب وصل مختلف عن اللي طلبته ولم يتم الرد بسرعة.',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // أغلب التقييمات إيجابية كما في المتاجر الحقيقية
        $rating = fake()->randomElement([5, 5, 5, 5, 4, 4, 4, 3, 2, 1]);

        return [
            'store_profile_id' => StoreProfile::factory(),
            'user_id' => User::factory()->customer(),
            'order_id' => null,
            'rating' => $rating,
            'comment' => fake()->boolean(85) ? fake()->randomElement(self::COMMENTS[$rating]) : null,
            'is_visible' => true,
            'created_at' => fake()->dateTimeBetween('-90 days', 'now'),
        ];
    }

    public function rating(int $rating): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
            'comment' => fake()->randomElement(self::COMMENTS[$rating]),
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['is_visible' => false]);
    }
}
