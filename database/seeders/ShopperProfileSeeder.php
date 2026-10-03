<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ShopperProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class ShopperProfileSeeder extends Seeder
{
    /**
     * حسابات المتسوقين مع بروفايلاتها وتصنيفاتها.
     */
    public function run(): void
    {
        // التصنيفات مطلوبة مسبقًا (CategorySeeder)
        $categoryIds = Category::query()->pluck('id');

        // متسوق ثابت للاختبار
        $user = User::create([
            'name' => 'متسوق تجريبي',
            'phone' => '966530000000',
            'email' => 'shopper@gift.test',
            'user_type' => 'shopper',
            'status' => 'active',
            'phone_verified_at' => now(),
        ]);

        $shopper = ShopperProfile::factory()->approved()->create([
            'user_id' => $user->id,
        ]);

        $shopper->categories()->sync($categoryIds->take(3));

        // متسوقون معتمدون
        ShopperProfile::factory()
            ->count(10)
            ->approved()
            ->create()
            ->each(fn (ShopperProfile $shopper) => $this->attachCategories($shopper, $categoryIds));

        // متسوقون قيد المراجعة
        ShopperProfile::factory()
            ->count(4)
            ->pending()
            ->create()
            ->each(fn (ShopperProfile $shopper) => $this->attachCategories($shopper, $categoryIds));

        // متسوقون مسودة — بدون تصنيفات بعد
        ShopperProfile::factory()->count(2)->draft()->create();

        // متسوقون مرفوضون
        ShopperProfile::factory()
            ->count(2)
            ->rejected()
            ->create()
            ->each(fn (ShopperProfile $shopper) => $this->attachCategories($shopper, $categoryIds));
    }

    /**
     * ربط المتسوق بمجموعة تصنيفات عشوائية دون تكرار.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $categoryIds
     */
    protected function attachCategories(ShopperProfile $shopper, $categoryIds): void
    {
        $shopper->categories()->sync(
            $categoryIds->random(fake()->numberBetween(1, 4))
        );
    }
}
