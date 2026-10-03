<?php

namespace Database\Seeders;

use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class StoreProfileSeeder extends Seeder
{
    /**
     * حسابات المتاجر مع بروفايلاتها وفروعها.
     */
    public function run(): void
    {
        // متجر ثابت للاختبار
        $owner = User::create([
            'name' => 'صاحب متجر تجريبي',
            'phone' => '966510000000',
            'email' => 'store@gift.test',
            'user_type' => 'store',
            'status' => 'active',
            'phone_verified_at' => now(),
        ]);

        $store = StoreProfile::factory()->approved()->create([
            'user_id' => $owner->id,
            'store_name' => 'متجر الهدايا التجريبي',
            'email' => 'store-profile@gift.test',
        ]);

        $this->seedBranches($store);

        // متاجر معتمدة
        StoreProfile::factory()
            ->count(8)
            ->approved()
            ->create()
            ->each(fn (StoreProfile $store) => $this->seedBranches($store));

        // متاجر قيد المراجعة
        StoreProfile::factory()
            ->count(3)
            ->pending()
            ->create()
            ->each(fn (StoreProfile $store) => $this->seedBranches($store, 1));

        // متاجر مسودة (لم تُرسل بعد) — بدون فروع
        StoreProfile::factory()->count(2)->draft()->create();

        // متاجر مرفوضة
        StoreProfile::factory()->count(2)->rejected()->create();
    }

    /**
     * إنشاء فرع رئيسي + فروع إضافية للمتجر.
     */
    protected function seedBranches(StoreProfile $store, ?int $extra = null): void
    {
        StoreBranch::factory()->main()->create([
            'store_profile_id' => $store->id,
            'phone' => $store->phone,
        ]);

        $extra ??= fake()->numberBetween(1, 3);

        if ($extra > 0) {
            StoreBranch::factory()
                ->count($extra)
                ->create(['store_profile_id' => $store->id]);
        }
    }
}
