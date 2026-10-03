<?php

namespace Database\Seeders;

use App\Models\StoreProfile;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * تقييمات عملاء واقعية للمتاجر المعتمدة. تُحدَّث rating_avg / rating_count
 * تلقائيًا عبر أحداث النموذج. قابل لإعادة التشغيل: المتاجر التي لديها تقييمات تُتجاوز.
 */
class StoreReviewSeeder extends Seeder
{
    public function run(): void
    {
        $customers = User::query()->where('user_type', 'customer')->where('status', 'active')->pluck('id');

        if ($customers->isEmpty()) {
            $this->command?->warn('StoreReviewSeeder: no customers found, run UserSeeder first.');

            return;
        }

        $stores  = StoreProfile::query()->where('status', 'approved')->orderBy('id')->get();
        $created = 0;

        foreach ($stores as $store) {
            if ($store->reviews()->exists()) {
                continue;
            }

            $count = fake()->numberBetween(4, 14);

            // عميل واحد لا يقيّم المتجر نفسه مرتين
            foreach ($customers->shuffle()->take($count) as $customerId) {
                StoreReview::factory()->create([
                    'store_profile_id' => $store->id,
                    'user_id'          => $customerId,
                ]);
                $created++;
            }
        }

        $this->command?->info("StoreReviewSeeder: {$created} reviews created.");
    }
}
